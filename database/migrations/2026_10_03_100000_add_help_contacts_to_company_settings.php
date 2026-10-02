<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact details for the floating "Need help?" box (client portal and the login pages), editable in
 * Company & Tax Settings. Starts with the details published on the website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('help_enabled')->default(true);
            $table->string('help_phone', 50)->nullable()->default('+977 9761008090');
            $table->string('help_whatsapp', 30)->nullable()->default('9779761008090');
            $table->string('help_email')->nullable()->default('info@civoraxinfra.com');
            $table->string('help_website')->nullable()->default('https://civoraxinfra.com');
            $table->string('help_facebook')->nullable();
            $table->string('help_hours', 100)->nullable()->default('Sun–Fri, 10 AM – 6 PM');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['help_enabled', 'help_phone', 'help_whatsapp', 'help_email', 'help_website', 'help_facebook', 'help_hours']);
        });
    }
};
