<?php

namespace WPML\Core;

use \WPML\Core\Twig\Environment;
use \WPML\Core\Twig\Error\LoaderError;
use \WPML\Core\Twig\Error\RuntimeError;
use \WPML\Core\Twig\Markup;
use \WPML\Core\Twig\Sandbox\SecurityError;
use \WPML\Core\Twig\Sandbox\SecurityNotAllowedTagError;
use \WPML\Core\Twig\Sandbox\SecurityNotAllowedFilterError;
use \WPML\Core\Twig\Sandbox\SecurityNotAllowedFunctionError;
use \WPML\Core\Twig\Source;
use \WPML\Core\Twig\Template;

/* pointer-ui.twig */
class __TwigTemplate_a80e2a19cfcfe60300a0115b46dbc60e8f4687e8dea2da75cee74f02a1f26029 extends \WPML\Core\Twig\Template
{
    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = [])
    {
        // line 1
        echo "<div class=\"wcml-pointer-block\" data-selector=\"";
        echo \WPML\Core\twig_escape_filter($this->env, ($context["selector"] ?? null), "html", null, true);
        echo "\" data-insert-method=\"";
        echo \WPML\Core\twig_escape_filter($this->env, ($context["insert_method"] ?? null), "html", null, true);
        echo "\" style=\"display:none;\">
    <a id=\"wcml-pointer-target-";
        // line 2
        echo \WPML\Core\twig_escape_filter($this->env, ($context["pointer"] ?? null), "html", null, true);
        echo "\" href=\"javascript:void(0)\" class=\"otgs-ico-wpml wcml-pointer-link\"
        data-wcml-open-pointer=\"wcml-pointer-";
        // line 3
        echo \WPML\Core\twig_escape_filter($this->env, ($context["pointer"] ?? null), "html", null, true);
        echo "\" title=\"";
        echo \WPML\Core\twig_escape_filter($this->env, $this->getAttribute(($context["description"] ?? null), "trnsl_title", []));
        echo "\">";
        echo \WPML\Core\twig_escape_filter($this->env, $this->getAttribute(($context["description"] ?? null), "trnsl_title", []));
        echo "</a>

    <div id=\"wcml-pointer-";
        // line 5
        echo \WPML\Core\twig_escape_filter($this->env, ($context["pointer"] ?? null), "html", null, true);
        echo "\" style=\"display:none;\">
        <div class=\"wcml-pointer-inner\">
            <p class=\"wcml-information-paragraph\">
                ";
        // line 8
        echo $this->getAttribute(($context["description"] ?? null), "content", []);
        echo "
            </p>
            ";
        // line 10
        if ($this->getAttribute(($context["description"] ?? null), "doc_link", [])) {
            // line 11
            echo "                <p class=\"wcml-information-link\">
                    <a class=\"wcml-external-link\" href=\"";
            // line 12
            echo \WPML\Core\twig_escape_filter($this->env, $this->getAttribute(($context["description"] ?? null), "doc_link", []), "html", null, true);
            echo "\" target=\"_blank\">
                        ";
            // line 13
            echo \WPML\Core\twig_escape_filter($this->env, $this->getAttribute(($context["description"] ?? null), "doc_link_text", []), "html", null, true);
            echo "
                    </a>
                </p>
            ";
        }
        // line 17
        echo "        </div>
    </div>
</div>";
    }

    public function getTemplateName()
    {
        return "pointer-ui.twig";
    }

    public function isTraitable()
    {
        return false;
    }

    public function getDebugInfo()
    {
        return array (  79 => 17,  72 => 13,  68 => 12,  65 => 11,  63 => 10,  58 => 8,  52 => 5,  43 => 3,  39 => 2,  32 => 1,);
    }

    /** @deprecated since 1.27 (to be removed in 2.0). Use getSourceContext() instead */
    public function getSource()
    {
        @trigger_error('The '.__METHOD__.' method is deprecated since version 1.27 and will be removed in 2.0. Use getSourceContext() instead.', E_USER_DEPRECATED);

        return $this->getSourceContext()->getCode();
    }

    public function getSourceContext()
    {
        return new Source("", "pointer-ui.twig", "/home/tagagenc/work.tagagency.it/wp-content/plugins/woocommerce-multilingual/templates/pointer-ui.twig");
    }
}
