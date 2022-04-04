<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class SplitTwofaccountsUriInMultipleColumns extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $driver = Schema::connection($this->getConnection())->getConnection()->getDriverName();

        // as SQLITE disallow to add a not nullable column without default
        // value when altering a table we add all columns as nullable and
        // change them right after to not nullable column
        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->string('otp_type', 10)->nullable();
            $table->text('secret')->nullable();
            $table->string('algorithm', 20)->nullable();
            $table->unsignedSmallInteger('digits')->nullable();
            $table->unsignedInteger('period')->nullable();
            $table->unsignedBigInteger('counter')->nullable();
        });
        
        Schema::table('twofaccounts', function (Blueprint $table){
            $table->string('otp_type', 10)->nullable(false)->change();
            $table->text('secret')->nullable(false)->change();
            $table->string('algorithm', 20)->nullable(false)->change();
            $table->unsignedSmallInteger('digits')->nullable(false)->change();
        });

        // Apply previous migration 'AlterEncryptedColumnsToText' even to sqlite base
        if ('sqlite' === $driver) {
            
            Schema::table('twofaccounts', function (Blueprint $table) {
                $table->text('account')->change();
            });

            Schema::table('twofaccounts', function (Blueprint $table) {
                $table->text('uri')->change();
            });
        }

        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->renameColumn('uri', 'legacy_uri');
        });
        

        $twofaccounts = DB::table('twofaccounts')->select('id', 'legacy_uri')->get();
        $settingService = resolve('App\Services\SettingService');

        foreach ($twofaccounts as $twofaccount) {
            try {
                $legacy_uri = $settingService->get('useEncryption') ? Crypt::decryptString($twofaccount->legacy_uri) : $twofaccount->legacy_uri;
                $token = \OTPHP\Factory::loadFromProvisioningUri($legacy_uri);

                $affected = DB::table('twofaccounts')
                    ->where('id', $twofaccount->id)
                    ->update([
                        'otp_type'  => get_class($token) === 'OTPHP\TOTP' ? 'totp' : 'hotp',
                        'secret'    => $settingService->get('useEncryption') ? Crypt::encryptString($token->getSecret()) : $token->getSecret(),
                        'algorithm' => $token->getDigest(),
                        'digits'    => $token->getDigits(),
                        'period'    => $token->hasParameter('period') ? $token->getParameter('period') : null,
                        'counter'   => $token->hasParameter('counter') ? $token->getParameter('counter') : null
                    ]);
            }
            catch(Exception $ex)
            {
                Log::error($ex->getMessage());
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropColumn('otp_type');
        });

        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropColumn('secret');
        });

        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropColumn('algorithm');
        });

        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropColumn('digits');
        });

        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropColumn('period');
        });
        
        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropColumn('counter');
        });

        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->renameColumn('legacy_uri', 'uri');
        });
    }
}
