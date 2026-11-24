<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(array $query): string
{
    return '/?' . http_build_query(array_filter($query, static fn (mixed $value): bool => $value !== null), '', '&', PHP_QUERY_RFC3986);
}

function field(string $name, string $label, string $type = 'text', bool $required = true, mixed $value = null): void
{
    echo '<label>', e($label), '<input name="data[', e($name), ']" type="', e($type), '"';
    if ($required) {
        echo ' required';
    }
    if ($type === 'number') {
        echo ' min="1" step="1" inputmode="numeric"';
    }
    if ($type === 'password') {
        echo ' autocomplete="new-password" minlength="12"';
    }
    echo ' value="', e($value), '"></label>';
}

function selectField(string $name, string $label, array $options, bool $required = true): void
{
    echo '<label>', e($label), '<select name="data[', e($name), ']"', $required ? ' required' : '', '>';
    if (!$required) {
        echo '<option value="">请选择</option>';
    }
    foreach ($options as $value => $display) {
        echo '<option value="', e($value), '">', e($display), '</option>';
    }
    echo '</select></label>';
}

function operation(string $csrf, string $action, string $button, callable $fields, array $hidden = []): void
{
    echo '<form method="post" class="operation"><input type="hidden" name="csrf" value="', e($csrf), '">',
        '<input type="hidden" name="action" value="', e($action), '">',
        '<input type="hidden" name="request_key" value="', e(bin2hex(random_bytes(16))), '">';
    foreach ($hidden as $name => $value) {
        echo '<input type="hidden" name="data[', e($name), ']" value="', e($value), '">';
    }
    echo '<div class="form-grid">';
    $fields();
    echo '</div><button class="primary" type="submit">', e($button), '</button></form>';
}

function formPanel(string $title, string $csrf, string $action, string $button, callable $fields, array $hidden = []): void
{
    echo '<details class="panel form-panel"><summary>', e($title), '</summary>';
    operation($csrf, $action, $button, $fields, $hidden);
    echo '</details>';
}
