@php $urls = $getRecord()->photoUrls(); @endphp

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.6rem;">
    @foreach ($urls as $url)
        <a href="{{ $url }}" target="_blank" rel="noopener" style="display:block;aspect-ratio:4/3;border-radius:.6rem;overflow:hidden;background:rgb(0 0 0 / .05);">
            <img src="{{ $url }}" alt="Site photo {{ $loop->iteration }}" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
        </a>
    @endforeach
</div>
