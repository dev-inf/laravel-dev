@include('front._partials/header')
<article>
	<h2>{{ $entry->title }}</h2>
	{{ $entry->body }}
</article>

@include('front._partials/footer')
