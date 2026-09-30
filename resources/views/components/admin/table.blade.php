{{--
    <x-admin.table> — admin list table (PRD §11.0). Scrolls horizontally inside its own wrapper on
    small screens. headers: list<string>; default slot = <tr> rows; `empty` slot shown when
    :empty="true" (every list has an empty state). Pagination goes after it via $paginator->links().
--}}
@props(['headers' => [], 'caption' => null, 'empty' => false])

<div {{ $attributes->class('ui-card') }}>
    @if ($empty)
        {{ $emptyState ?? '' }}
    @else
        <div class="admin-table-wrap">
            <table class="admin-table">
                @if ($caption)
                    <caption class="visually-hidden">{{ $caption }}</caption>
                @endif
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th scope="col">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    {{ $slot }}
                </tbody>
            </table>
        </div>
    @endif
</div>
