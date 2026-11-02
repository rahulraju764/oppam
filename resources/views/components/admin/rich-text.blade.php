{{--
    <x-admin.rich-text> — the shared A08 editor (resources/js/alpine/rich-text.js): a labelled
    contenteditable with bold, italic, lists and a link. A convenience only: whatever it sends is
    sanitised on the server (HtmlSanitizer).

    label, name, model (Livewire property)
--}}
@props(['label', 'name', 'model'])

@php
    $id = 'rich-'.$name;
    $errors ??= new \Illuminate\Support\ViewErrorBag;
    $hasError = $errors->has($model);
@endphp

<div class="ui-field rich-text" x-data="richText('{{ $model }}')">
    <span class="form-label d-block" id="{{ $id }}-label">{{ $label }} *</span>
    <div class="rich-text__toolbar" role="toolbar" aria-labelledby="{{ $id }}-label">
        <button type="button" class="rich-text__btn" x-on:mousedown.prevent="" x-on:click="format('bold')" aria-label="{{ __('Bold') }}"><i class="fa fa-bold" aria-hidden="true"></i></button>
        <button type="button" class="rich-text__btn" x-on:mousedown.prevent="" x-on:click="format('italic')" aria-label="{{ __('Italic') }}"><i class="fa fa-italic" aria-hidden="true"></i></button>
        <button type="button" class="rich-text__btn" x-on:mousedown.prevent="" x-on:click="format('insertUnorderedList')" aria-label="{{ __('Bulleted list') }}"><i class="fa fa-list-ul" aria-hidden="true"></i></button>
        <button type="button" class="rich-text__btn" x-on:mousedown.prevent="" x-on:click="format('insertOrderedList')" aria-label="{{ __('Numbered list') }}"><i class="fa fa-list-ol" aria-hidden="true"></i></button>
        <button type="button" class="rich-text__btn" x-on:mousedown.prevent="" x-on:click="openLink()" aria-label="{{ __('Add a link') }}" x-bind:aria-expanded="linking"><i class="fa fa-link" aria-hidden="true"></i></button>
    </div>
    <div class="rich-text__link" x-show="linking" x-cloak>
        <label class="visually-hidden" for="{{ $id }}-url">{{ __('Link address') }}</label>
        <input type="url" class="form-control" id="{{ $id }}-url" name="{{ $name }}-url" placeholder="https://" x-model="linkUrl" x-on:keydown.enter.prevent="applyLink()">
        <button type="button" class="rich-text__btn" x-on:click="applyLink()">{{ __('Add') }}</button>
    </div>
    <div wire:ignore>
        <div x-ref="editor" id="{{ $id }}" dusk="{{ $id }}" class="form-control rich-text__area" contenteditable="true" role="textbox" aria-multiline="true"
             aria-labelledby="{{ $id }}-label" x-on:input="sync()" x-on:blur="remember(); sync()"
             @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif></div>
    </div>
    @if ($hasError)
        <p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $errors->first($model) }}</p>
    @endif
</div>
