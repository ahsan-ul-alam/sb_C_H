@props(['icon' => 'layers', 'title'])
<div class="pt-empty"><span class="pt-empty-ico"><x-icon :name="$icon" /></span><h3>{{ $title }}</h3><p>{{ $slot }}</p></div>
