<?php
/**
 * The plumbing behind the two buttons on the settings page.
 *
 * A field in system.xml normally stores something. These two store nothing:
 * they render a button, and the button asks the server a question. Because no
 * input with a name is rendered, saving the page cannot write a value for
 * them, and because the answer comes from the server the encrypted API key
 * never has to reach the browser.
 *
 * The behaviour is wired with a text/x-magento-init block rather than an
 * onclick attribute, which is how the rest of the Magento admin does it and
 * what keeps the page working under a content security policy.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;

abstract class AbstractCheck extends Field
{
    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @var Json
     */
    private $json;

    public function __construct(Context $context, FormKey $formKey, Json $json, array $data = [])
    {
        parent::__construct($context, $data);
        $this->formKey = $formKey;
        $this->json = $json;
    }

    /**
     * The admin route this button posts to, as route/controller/action.
     */
    abstract protected function actionPath(): string;

    abstract protected function buttonLabel(): Phrase;

    /**
     * True when the operator has to say who the message goes to.
     */
    protected function needsNumber(): bool
    {
        return false;
    }

    protected function numberPlaceholder(): Phrase
    {
        return __('254712345678');
    }

    /**
     * There is nothing to inherit from a parent scope and nothing to reset to
     * a default, because this field holds no value. Left alone, Magento would
     * render a "Use Website" checkbox next to a button, which reads as though
     * pressing it did something different at each scope.
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue()->unsInherit();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $base = $element->getHtmlId();
        $buttonId = $base . '_button';
        $resultId = $base . '_result';
        $numberId = $this->needsNumber() ? $base . '_number' : '';

        $html = '';

        if ($numberId !== '') {
            $html .= sprintf(
                '<input type="text" id="%s" class="input-text admin__control-text" style="max-width:200px;margin-right:8px" placeholder="%s"> ',
                $this->escapeHtmlAttr($numberId),
                $this->escapeHtmlAttr((string) $this->numberPlaceholder())
            );
        }

        $html .= $this->getLayout()->createBlock(Button::class)
            ->setData(
                [
                    'id' => $buttonId,
                    'label' => (string) $this->buttonLabel(),
                    'type' => 'button',
                ]
            )->toHtml();

        $html .= sprintf('<div id="%s" style="margin-top:8px"></div>', $this->escapeHtmlAttr($resultId));

        $html .= sprintf(
            '<script type="text/x-magento-init">%s</script>',
            $this->json->serialize(
                [
                    '#' . $buttonId => [
                        'Upeo_Njiwa/js/check' => [
                            'url' => $this->checkUrl(),
                            'formKey' => $this->formKey->getFormKey(),
                            'resultId' => $resultId,
                            'numberId' => $numberId,
                        ],
                    ],
                ]
            )
        );

        return $html;
    }

    /**
     * The scope the admin is looking at travels with the request, so the
     * check reads the same website or store view the page is editing rather
     * than whatever is saved at the default scope.
     */
    private function checkUrl(): string
    {
        $params = [];

        foreach (['website', 'store'] as $name) {
            $value = $this->getRequest()->getParam($name);
            if (is_scalar($value) && (string) $value !== '') {
                $params[$name] = (string) $value;
            }
        }

        return $this->getUrl($this->actionPath(), $params);
    }
}
