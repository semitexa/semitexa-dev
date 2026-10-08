{# {{pageName}} page template. The layout is the bundled theme's; pass `layout` to use your own. #}
{% extends layout ?? '@project-layouts-theme-base/layouts/one-column.html.twig' %}

{% block title %}{{ title | default('{{pageName}}') }}{% endblock %}

{% block main %}
    <div class="page-{{kebabName}}">
        <h1>{{ title | default('{{pageName}}') }}</h1>
    </div>
{% endblock %}
