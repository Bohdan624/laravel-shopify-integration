<div>
    @foreach ($body as $key => $item)
        <div>
            <pre>
                {{ $key }} : {{ $item }}
            </pre>
        </div>
    @endforeach
</div>