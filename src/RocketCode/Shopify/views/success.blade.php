@foreach($data as $key => $item)

<ul class="list-group">
    <li class="list-group-item">{{ $key }} = {{ $item }} </li>
</ul>

@endforeach