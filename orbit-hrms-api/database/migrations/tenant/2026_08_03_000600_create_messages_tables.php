<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages and notifications, both polled through one cursor.
 *
 * The client asks "anything after id N?" every few seconds, so every read
 * here is `WHERE recipient = ? AND id > ? ORDER BY id` — the indexes below
 * exist for that one query shape and nothing else. A poll that misses its
 * index would turn a quiet office into a constant table scan.
 *
 * A message is one row plus one recipient row per person. Broadcasts fan out
 * at write time rather than being resolved on read: a poll must stay a
 * single indexed lookup, and working out "who is in this audience" on every
 * tick would defeat that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            // What the sender chose: a direct message, or a broadcast to a
            // role or the whole company. Kept for display — the actual
            // delivery list lives in message_recipients.
            $table->string('audience')->default('Direct message');

            $table->string('subject')->nullable();
            $table->text('body');

            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The poll: everything for this person after their cursor.
            $table->index(['employee_id', 'message_id']);
            // The unread badge.
            $table->index(['employee_id', 'read_at']);
            // One delivery per person per message.
            $table->unique(['message_id', 'employee_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('body')->nullable();
            // 'leave', 'medical', 'salary', 'finance' — drives the icon.
            $table->string('type')->default('general');

            // Where clicking it should land, as a route name plus params.
            $table->string('route_name')->nullable();
            $table->json('route_params')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'id']);
            $table->index(['employee_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('message_recipients');
        Schema::dropIfExists('messages');
    }
};
