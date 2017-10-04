<?php

use App\Model\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSystemAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('system_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('uid');
            $table->integer('account_id');
            $table->tinyInteger('type')->default(0)->comment("1 充值账户");
            $table->timestamps();
        });
        $this->seed();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('system_accounts');
    }

    public function seed()
    {

        $user = new User([
            'name'      => 'admin',
            'email'     => '348578429@qq.com',
            'avatar'    => '/images/avatars/default.png',
            'password'  => bcrypt('123456'),
            'api_token' => str_random(60),
            'settings'  => ['city' => ''],
        ]);
        $user->id = \App\Model\SystemUser::SYSTEM_UID;
        //创建系统账户
        $user = resolve(UserRepository::class)->create($user, User::USER_SYSTEM);


    }
}
