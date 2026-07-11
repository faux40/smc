<?php

namespace App\Jobs;

use App\Events\GeneratedDocumentsChanged;
use App\Models\GeneratedDocument;
use App\Models\MergeField;
use App\Support\DocMerge\DocumentMergeService;
use App\Support\DocMerge\MergeDataBuilder;
use App\Support\DocMerge\PdfConverter;
use App\Support\DocMerge\TemplateTranslator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates one document (Phase D2): template file -> ${key} translation
 * (with the org's list/multiline field types) -> OpenTBS merge with the
 * resolved variation data -> soffice PDF -> both outputs on the linode
 * disk. Failures mark the row failed (no retries — the run is
 * deterministic, the user re-requests after fixing the cause).
 */
class GenerateDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    private const DISK = 'linode';

    public function __construct(public readonly string $generatedDocumentId) {}

    public function handle(
        MergeDataBuilder $builder,
        DocumentMergeService $merger,
        PdfConverter $pdf,
    ): void {
        $doc = GeneratedDocument::query()
            ->withoutGlobalScope('organization')
            ->with(['template', 'organization'])
            ->find($this->generatedDocumentId);

        if ($doc === null || $doc->template === null) {
            return; // deleted while queued
        }

        $doc->update(['status' => 'processing']);

        $workDir = null;

        try {
            $template = $doc->template;
            $org = $doc->organization;

            $workDir = sys_get_temp_dir().'/docgen_'.$doc->id;
            mkdir($workDir, 0700, true);

            // 1. template file: linode -> local
            $source = "{$workDir}/template.{$template->extension}";
            file_put_contents($source, Storage::disk(self::DISK)->get($template->path));

            // 2. resolve the org's data for the requested variation
            $data = $builder->build($org, $doc->location ?: null, $doc->department ?: null);

            // 3. translate ${key} -> TBS, typed by the field registry
            $fieldTypes = MergeField::query()
                ->visibleTo($org->id)
                ->pluck('type', 'key');
            $translator = new TemplateTranslator(
                listFieldKeys: $fieldTypes->filter(fn ($t) => $t === 'list')->keys()->all(),
                multilineFieldKeys: $fieldTypes->filter(fn ($t) => $t === 'multiline')->keys()->all(),
            );
            $translated = $translator->translateFile($source, "{$workDir}/translated.{$template->extension}");

            // 4. blocks: each minted block name gets its field's rows
            $blocks = [];
            foreach ($translator->generatedBlockMap() as $unique => $fieldKey) {
                $blocks[$unique] = $data['listRows'][$fieldKey] ?? [];
            }

            // 5. merge + convert
            $merged = $merger->merge($translated, $data['fields'], "{$workDir}/merged.{$template->extension}", $blocks);
            $pdfPath = $pdf->toPdf($merged, $workDir);

            // 6. persist outputs
            $base = "generated-documents/{$org->id}/{$doc->id}";
            Storage::disk(self::DISK)->put("{$base}.{$template->extension}", file_get_contents($merged));
            Storage::disk(self::DISK)->put("{$base}.pdf", file_get_contents($pdfPath));

            $doc->update([
                'status' => 'done',
                'merged_path' => "{$base}.{$template->extension}",
                'pdf_path' => "{$base}.pdf",
                'merge_snapshot' => $data,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Document generation failed', [
                'generated_document_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);
            $doc->update(['status' => 'failed', 'error' => $e->getMessage()]);
        } finally {
            if ($workDir !== null && is_dir($workDir)) {
                foreach (glob("{$workDir}/*") ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($workDir);
            }
        }

        event(new GeneratedDocumentsChanged($doc->org_id));
    }
}
