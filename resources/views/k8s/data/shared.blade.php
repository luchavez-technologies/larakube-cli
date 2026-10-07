@if(isset($engine) && $engine === 'pocketbase')
@include('k8s.data.pocketbase')
@elseif(isset($engine) && $engine === 'wordpress')
@include('k8s.data.wordpress')
@else
@include('k8s.data.directus')
@endif
