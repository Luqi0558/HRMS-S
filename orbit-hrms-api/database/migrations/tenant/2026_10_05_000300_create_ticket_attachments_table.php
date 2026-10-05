<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to a ticket.
 *
 * The ticket modal had an "Add attachment" control that pushed a name with a
 * timestamp id into browser state — no file was uploaded, teammates never saw
 * it, and the board's refresh wiped it within seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()
                ->constrained('employees')->nullOnDelete();

            // Stored under a generated name; original_name is a display label
            // only, since the uploader controls it.
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type')->nullable();

            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
