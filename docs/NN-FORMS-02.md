# NN-FORMS-02 — Landing integration contract

Pages now exposes a theme-neutral `contact_form` value to public page templates.

For a page at `/pages/estimate`, a theme override such as
`themes/<theme>/module-templates/pages/landing.twig` may use:

```twig
{% if contact_form.result == 'sent' %}
  <p>Thanks. Your message was sent.</p>
{% elseif contact_form.result == 'invalid' %}
  <p>Please check the form and try again.</p>
{% elseif contact_form.result == 'limited' %}
  <p>Please wait before trying again.</p>
{% elseif contact_form.result == 'unavailable' %}
  <p>The form is temporarily unavailable.</p>
{% endif %}

<form method="post" action="{{ contact_form.action }}">
  <input type="hidden" name="_token" value="{{ contact_form.csrf_token }}">
  <input type="hidden" name="return_to" value="{{ contact_form.return_to }}">
  <input type="text" name="website" tabindex="-1" autocomplete="off">
  <input name="name" required>
  <input name="email" type="email">
  <textarea name="message" required></textarea>
  <button type="submit">Send</button>
</form>
```

The fallback Pages landing is intentionally unchanged. Forms are opt-in presentation owned by the active theme.

`contact_form.result` is restricted to `sent`, `invalid`, `limited`, `unavailable`, or `null`.
