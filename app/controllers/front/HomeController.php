<?php

namespace App\Controllers\Front;

use App\Models\Page;
use App\Models\Article;

class HomeController extends \BaseController {
    /*
      |--------------------------------------------------------------------------
      | Default Home Controller
      |--------------------------------------------------------------------------
      |
      | You may wish to use controllers instead of, or in addition to, Closure
      | based routes. That's great! Here is an example controller method to
      | get you started. To route to this controller, just add the route:
      |
      |	Route::get('/', 'HomeController@showWelcome');
      |
     */

    public function index() {
        return \View::make('front.index')->with('entry', Page::where('slug', 'welcome')->first());
    }

    public function blog() {
        return \View::make('front.articles')->with('entries', Article::orderBy('created_at', 'desc')->get());
    }

    public function viewBlog($slug) {
        $article = Article::where('slug', $slug)->first();

        if (!$article)
            App::abort(404, 'Article not found');

        return \View::make('front.article')->with('entry', $article);
    }

    public function viewPage($slug) {
        $page = Page::where('slug', $slug)->first();

        if (!$page)
            App::abort(404, 'Page not found');

        return \View::make('front.page')->with('entry', $page);
    }
    
    public function test(){
        
    }

}
