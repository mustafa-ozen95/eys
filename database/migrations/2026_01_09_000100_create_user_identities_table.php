<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merkezî kimlikle yerel hesabın eşleşmesi.
 *
 * AYRI BİR TABLO, bilinçli: `users` tablosuna dokunmuyoruz. Bu sayede
 * merkezle bağ kurmak ya da koparmak, panelin kendi üyeliğini hiç
 * ilgilendirmiyor — yerel giriş her zaman yerinde duruyor.
 *
 * Eşleşme SUBJECT üzerinden kurulur, e-posta üzerinden değil: kişi merkezde
 * e-postasını değiştirse de bağ kopmaz. E-posta yalnızca İLK eşleşmede,
 * doğrulanmışsa ve yerelde tek karşılığı varsa kullanılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /* Hangi merkez: ileride ikinci bir sağlayıcı eklenirse ayrışsın. */
            $table->string('provider', 40)->default('kimlik');
            $table->string('subject');

            $table->string('email')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'subject']);
            $table->unique(['provider', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
