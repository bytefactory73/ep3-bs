<?php

namespace Base\View\Helper;

use Base\Manager\OptionManager;
use Base\Service\MoneyCalculator;
use Zend\View\Helper\AbstractHelper;

class PriceFormat extends AbstractHelper
{

    protected $optionManager;

    public function __construct(OptionManager $optionManager)
    {
        $this->optionManager = $optionManager;
    }

    public function __invoke($price, $rate = null, $gross = null, $perTime = null, $perQuantity = null, $perText = null, $break = true, $bold = true, $symbolic = true)
    {
        $view = $this->getView();
        $html = '';

        if ($symbolic) {
            $html .= '<span class="symbolic symbolic-tag">';
        }

        if ($bold) {
            $html .= '<b>' . $view->currencyFormat(MoneyCalculator::fromCents($price)) . '</b>';
        } else {
            $html .= $view->currencyFormat(MoneyCalculator::fromCents($price));
        }

        if ($perText) {
            $html .= ' ' . $view->t($perText);
        }

        if ($perTime || $perQuantity) {
            $html .= ' / ';

            if ($perTime) {
                $html .= $view->prettyTime($perTime);
            }

            if ($perTime && $perQuantity) {
                $html .= ' &amp; ';
            }

            if ($perQuantity) {
                $html .= $this->optionManager->need('subject.square.unit');
            }
        }

        if ($rate && $gross) {

            if ($break) {
                $html .= '<br>';
            } else {
                $html .= ' &nbsp; ';
            }

            if ($gross) {
                $grossFormulation = $view->t('incl.');
            } else {
                $grossFormulation = $view->t('plus');
            }

            $html .= sprintf('<span class="small-text">%s %s%% %s</span>',
                $grossFormulation, $rate, $view->t('VAT'));
        }

        if ($symbolic) {
            $html .= '</span>';
        }

        return $html;
    }

}
