<?php

declare(strict_types=1);

it('asks for confirmation before paying an invoice with balance', function () {
    $tpl = file_get_contents(
        __DIR__ . '/../../../resources/views/cafe/user/invoice/view.tpl'
    );

    expect($tpl)->toContain('hx-post="/user/invoice/pay_balance"');

    preg_match(
        '/<button[^>]*hx-post="\/user\/invoice\/pay_balance"[^>]*>/',
        $tpl,
        $matches
    );

    expect($matches)->not->toBeEmpty()
        ->and($matches[0])->toContain('hx-confirm=');
});
