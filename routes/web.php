<?php
Auth::routes();
Route::group(['namespace' => 'Web',], function () {
    Route::get('/', 'QuestionsController@index');
    Route::get('/home', 'HomeController@index');
    Route::get('email/verify/{token}', ['as' => 'email.verify', 'uses' => 'EmailController@verify']);
    Route::resource('questions', 'QuestionsController', [
        'names' => [
            'create' => 'question.create',
            'show'   => 'question.show',
        ]
    ]);
    Route::post('questions/{question}/answer', 'AnswersController@store');
    Route::get('question/{question}/follow', 'QuestionFollowController@follow');

    Route::get('notifications', 'NotificationsController@index');
    Route::get('notifications/{notification}', 'NotificationsController@show');


    Route::get('avatar', 'UsersController@avatar');
    Route::post('avatar', 'UsersController@changeAvatar');

    Route::get('password', 'PasswordController@password');
    Route::post('password/update', 'PasswordController@update');

    Route::get('setting', 'SettingController@index');


    Route::post('setting', 'SettingController@store');
    Route::group(['middleware' => ['auth']], function () {
        Route::get('inbox', 'InboxController@index');
        Route::get('inbox/{dialogId}', 'InboxController@show');
        Route::post('inbox/{dialogId}/store', 'InboxController@store');

        Route::group(['prefix' => 'account'], function () {
            Route::get('/index', 'AccountController@index');
            Route::post('/recharge', 'AccountController@recharge');
            Route::post('/transfer', 'AccountController@transfer');


        });
    });
});
