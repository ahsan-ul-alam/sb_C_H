@props(['lot'])
<span {{ $attributes->merge(['class' => 'badge badge-stage-'.$lot->stage()]) }}>{{ $lot->stageLabel() }}</span>
