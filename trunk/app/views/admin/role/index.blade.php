@extends('admin._layouts.default')
@section('main')
<h1>
    Phân quyền
</h1>
<hr>
<ul>
    <?php
    foreach ($routes as $r => $value):
        if ($r !== 'get|post|put|patch|delete admin'):
            ?>
            <li><?php echo $r ?></li>
    <?php endif;
endforeach; ?>
</ul>
@stop