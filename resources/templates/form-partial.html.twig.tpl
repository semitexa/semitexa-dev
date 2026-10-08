{#
 # The "{{title}}" form ({{className}}). Its fields come from the action, so
 # the controls, their rules and the server's cast never disagree.
 #
 #     {% include '@project-layouts-{{module}}/partials/{{kebab}}-form.html.twig' %}
 #}
{% set _fields %}
    {% for field in ui_form_fields('{{actionName}}') %}
        {{ component('platform.field', ui_field_props(field)) }}
    {% endfor %}
{% endset %}
{{ component('platform.form', {
    title: '{{title}}',
    showStatus: true,
    showSubmit: true,
    statusInitialMessage: '',
    autoFields: true,
    submitAction: '{{actionName}}',
    submitText: '{{submitText}}',
}, {content: _fields}) }}
