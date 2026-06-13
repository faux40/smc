<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\TrainingClass;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 2 of the TrainingWise migration: copy a class's attachment FILES
 * from the legacy AWS S3 year-buckets to the app's object store, and
 * create the polymorphic Attachment rows on the migrated TrainingClass.
 *
 * Run AFTER `tw:migrate {org}` has committed (it relies on legacy_tw_map
 * to resolve a TrainingWise class id to its new TrainingClass uuid).
 *
 *   php artisan tw:migrate-attachments 12 --dry-run     # report, no copy
 *   php artisan tw:migrate-attachments 12               # copy + record
 *
 * Source S3 access comes from the app's standard AWS creds in env
 * (READ-ONLY — we only HeadBucket/Head/Get/stream, never write to AWS):
 *   AWS_ACCESS_KEY_ID=  AWS_SECRET_ACCESS_KEY=
 * Each legacy year-bucket's region is auto-detected (they're us-west-1).
 * Target is the app's configured `linode` disk. Idempotent via
 * legacy_tw_map (entity `attachment_link`, keyed on attachments_classes.id);
 * the source object for a file is copied at most once per run.
 */
class MigrateTrainingWiseAttachments extends Command
{
    protected $signature = 'tw:migrate-attachments {twOrgId}
        {--dry-run : Report what would be copied, touch nothing}
        {--limit=0 : Cap the number of links processed (0 = all)}';

    protected $description = 'Copy TrainingWise class attachment files (S3) into SMC + record them';

    private const TARGET_DISK = 'linode';

    private Connection $tw;

    private array $srcDisks = [];        // bucket name => Storage disk

    private array $bucketRegion = [];    // bucket name => region (auto-detected)

    private array $copiedPaths = [];     // tw attachment id => new path (copy once per file)

    public function handle(): int
    {
        $this->tw = DB::connection('trainingwise');
        $twOrgId = (int) $this->argument('twOrgId');
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $orgId = DB::table('legacy_tw_map')->where('entity', 'org')->where('tw_id', $twOrgId)->value('new_id');
        if (! $orgId) {
            $this->error("Org {$twOrgId} hasn't been imported yet — run `tw:migrate {$twOrgId}` first.");

            return self::FAILURE;
        }

        if (! env('AWS_ACCESS_KEY_ID') || ! env('AWS_SECRET_ACCESS_KEY')) {
            $this->error('Set AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY (read-only) in .env before copying.');

            return self::FAILURE;
        }

        $uploaderId = User::where('org_id', $orgId)->orderBy('created_at')->value('id');
        if (! $uploaderId) {
            $this->error('No users found for this org — run the data import first.');

            return self::FAILURE;
        }

        // Class attachments for this org's (active) classes, with bucket name.
        $q = $this->tw->table('attachments_classes as ac')
            ->join('attachments as a', 'a.id', '=', 'ac.attachment_id')
            ->join('classes as cl', 'cl.id', '=', 'ac.class_id')
            ->join('s3buckets as b', 'b.id', '=', 'a.s3_bucket_id')
            ->where('cl.organization_id', $twOrgId)
            ->where('a.removed_date', '0000-00-00 00:00:00')
            ->where('ac.removed_date', '0000-00-00 00:00:00')
            ->where('cl.removed_date', '0000-00-00 00:00:00')
            ->orderBy('ac.id')
            ->select('ac.id as link_id', 'ac.class_id', 'a.id as att_id',
                'a.original_file_name', 'a.s3_file_path', 'a.file_size', 'b.name as bucket');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $links = $q->get();

        $this->info("Attachments for TW org {$twOrgId}: {$links->count()} link(s)".($dryRun ? '  [DRY RUN]' : ''));

        $bar = $this->output->createProgressBar($links->count());
        $bar->setFormat('  attachments: %current%/%max% [%bar%] %elapsed%');
        $done = 0;
        $skippedNoClass = 0;
        $missingObjects = 0;
        $alreadyDone = 0;

        foreach ($links as $row) {
            $classUuid = DB::table('legacy_tw_map')->where('entity', 'class')->where('tw_id', (int) $row->class_id)->value('new_id');
            if (! $classUuid) {
                $skippedNoClass++;
                $bar->advance();

                continue;
            }
            if (DB::table('legacy_tw_map')->where('entity', 'attachment_link')->where('tw_id', (int) $row->link_id)->exists()) {
                $alreadyDone++;
                $bar->advance();

                continue;
            }

            if ($dryRun) {
                // Just confirm the source object exists (cheap HEAD).
                if (! $this->sourceDisk($row->bucket)->exists($row->s3_file_path)) {
                    $missingObjects++;
                }
                $done++;
                $bar->advance();

                continue;
            }

            // Copy the file once per source attachment, reuse for extra links.
            $newPath = $this->copiedPaths[(int) $row->att_id] ?? null;
            if ($newPath === null) {
                $src = $this->sourceDisk($row->bucket);
                if (! $src->exists($row->s3_file_path)) {
                    $missingObjects++;
                    $bar->advance();

                    continue;
                }
                $newPath = 'attachments/'.Str::uuid().'-'.$row->original_file_name;
                $stream = $src->readStream($row->s3_file_path);
                $ok = Storage::disk(self::TARGET_DISK)->writeStream($newPath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($ok === false) {
                    $this->warn("  storage write failed for link {$row->link_id}");
                    $bar->advance();

                    continue;
                }
                $this->copiedPaths[(int) $row->att_id] = $newPath;
            }

            Model::unguarded(function () use ($row, $orgId, $uploaderId, $classUuid, $newPath) {
                $att = Attachment::create([
                    'org_id' => $orgId,
                    'attachable_type' => TrainingClass::class,
                    'attachable_id' => $classUuid,
                    'uploaded_by_user_id' => $uploaderId,
                    'filename' => $row->original_file_name,
                    'mime' => $this->mimeFor($row->original_file_name),
                    'size' => $row->file_size !== null ? (int) $row->file_size : null,
                    'disk' => self::TARGET_DISK,
                    'path' => $newPath,
                ]);
                DB::table('legacy_tw_map')->insert([
                    'entity' => 'attachment_link', 'tw_id' => (int) $row->link_id,
                    'new_id' => $att->id, 'new_org_id' => $orgId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
            $done++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info("processed={$done}  already-done={$alreadyDone}  unmapped-class={$skippedNoClass}  missing-source-object={$missingObjects}");
        if ($dryRun) {
            $this->warn('DRY RUN — no files copied, no rows written.');
        }

        return self::SUCCESS;
    }

    private function sourceDisk(string $bucket): Filesystem
    {
        return $this->srcDisks[$bucket] ??= Storage::build([
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => $this->regionFor($bucket),
            'bucket' => $bucket,
            'throw' => false,   // READ-ONLY usage; never write to this disk
        ]);
    }

    /** Auto-detect a legacy bucket's region (HeadBucket — read-only). */
    private function regionFor(string $bucket): string
    {
        return $this->bucketRegion[$bucket] ??= (function () use ($bucket) {
            try {
                $client = new S3Client([
                    'version' => 'latest',
                    'region' => 'us-west-1',
                    'credentials' => [
                        'key' => env('AWS_ACCESS_KEY_ID'),
                        'secret' => env('AWS_SECRET_ACCESS_KEY'),
                    ],
                ]);

                return $client->determineBucketRegion($bucket) ?: 'us-west-1';
            } catch (\Throwable) {
                return 'us-west-1';
            }
        })();
    }

    private function mimeFor(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
