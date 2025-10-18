<p>Hello <?= esc_html($name ?? 'Subscriber') ?>,</p>

<p>Click the link below to confirm your subscription:</p>

<p><a href="<?= esc_url($magic_link) ?>"><?= esc_html($magic_link) ?></a></p>

<p>Thank you,<br>The Take Flight Global Team</p>
