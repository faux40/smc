<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_fonts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            /*
             * Fonts belong to the ORG, not to a template (C6c). card_templates
             * version — `replace` mints a new row — so template-scoped fonts
             * would be orphaned by every design re-upload, silently reverting
             * the card to a substituted font, which is the exact failure this
             * feature exists to prevent. Org scope also means one upload
             * serves every design and every future version of it.
             *
             * No system scope: the families shipped in the image are the
             * config list (cards.supported_fonts), not rows here.
             */
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            // As the FILE declares it — never the filename. This is what a
            // template's declaration is matched against.
            $table->string('family');
            // The same, normalised (lowercased/trimmed), so the uniqueness
            // rule is the same comparison the matching uses. Two files for
            // one family would both be staged and LibreOffice would pick
            // whichever it liked.
            $table->string('family_key');
            $table->string('original_filename');
            $table->string('format', 8); // ttf | otf
            $table->string('path'); // on the linode disk
            $table->unsignedBigInteger('size');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['org_id', 'family_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_fonts');
    }
};
