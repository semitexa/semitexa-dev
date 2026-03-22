{# {{pageName}} page template #}
{% extends layout ?? '@layouts/base.html.twig' %}

{% block content %}
    <div class="page-{{kebabName}}">
        <h1>{{ title | default('{{pageName}}') }}</h1>
    </div>
{% endblock %}
