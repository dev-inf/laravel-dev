<?php

/*
  |--------------------------------------------------------------------------
  | Application Routes
  |--------------------------------------------------------------------------
  |
  | Here is where you can register all of the routes for an application.
  | It's a breeze. Simply tell Laravel the URIs it should respond to
  | and give it the Closure to execute when that URI is requested.
  |
 */
/* Frontend route */
Route::get('test', array('as' => 'test', 'before' => 'test:App\Controllers\Front\HomeController@test', 'uses' => 'App\Controllers\Front\HomeController@test'));
Route::get('/', array('as' => 'index', 'uses' => 'App\Controllers\Front\HomeController@index'));

// Article list
Route::get('blog', array('as' => 'article.list', 'uses' => 'App\Controllers\Front\HomeController@blog'));

// Single article
Route::get('blog/{slug}', array('as' => 'article', 'uses' => 'App\Controllers\Front\HomeController@viewBlog'));

// Single page
Route::get('{slug}', array('as' => 'page', 'uses' => 'App\Controllers\Front\HomeController@viewPage'))->where('slug', '^((?!admin).)*$');

// 404 Page
App::missing(function($exception) {
    return Response::view('front.404', array(), 404);
});




/* Admin route */
Route::get('admin/logout', array('as' => 'admin.logout', 'uses' => 'App\Controllers\Admin\AuthController@getLogout'));
Route::get('admin/login', array('as' => 'admin.login', 'uses' => 'App\Controllers\Admin\AuthController@getLogin'));
Route::post('admin/login', array('as' => 'admin.login.post', 'uses' => 'App\Controllers\Admin\AuthController@postLogin'));

Route::group(array('prefix' => 'admin', 'before' => 'auth.admin'), function() {
    Route::get('/', 'App\Controllers\Admin\PagesController@index');
    Route::resource('articles', 'App\Controllers\Admin\ArticlesController');
    Route::resource('pages', 'App\Controllers\Admin\PagesController');
    
    Route::get('role', array('as'=>'admin.role','before'=>'test','uses'=>'App\Controllers\Admin\RoleController@index'));
});
