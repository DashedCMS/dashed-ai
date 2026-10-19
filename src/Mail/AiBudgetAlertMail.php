<?php

namespace Dashed\DashedAi\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Dashed\DashedAi\Logging\AiLogSettings;
use Dashed\DashedCore\Models\Customsetting;

/** Beheerdersmelding: de AI-kosten van deze maand hebben een drempel van het budget bereikt. */
class AiBudgetAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public int $threshold, public float $spentUsd, public float $budgetUsd)
    {
    }

    public function build()
    {
        $subject = $this->threshold >= 100
            ? __('AI-budget overschreden: :bedrag van :budget', ['bedrag' => $this->usd($this->spentUsd), 'budget' => $this->usd($this->budgetUsd)])
            : __('AI-budget voor :procent% gebruikt: :bedrag van :budget', ['procent' => $this->threshold, 'bedrag' => $this->usd($this->spentUsd), 'budget' => $this->usd($this->budgetUsd)]);

        return $this
            ->from(Customsetting::get('site_from_email'), Customsetting::get('site_name'))
            ->subject($subject)
            ->html($this->htmlBody());
    }

    protected function usd(float $amount): string
    {
        return '$' . number_format($amount, 2, ',', '.');
    }

    protected function eur(float $amount): string
    {
        return '€' . number_format($amount * AiLogSettings::usdEurRate(), 2, ',', '.');
    }

    protected function logUrl(): ?string
    {
        return rescue(fn () => route('filament.dashed.resources.ai-logs.index'), null, false);
    }

    protected function htmlBody(): string
    {
        $html = '<p>' . e(__('De AI-kosten van :maand staan op :bedrag (ongeveer :eur), dat is :procent% van het maandbudget van :budget.', [
            'maand' => now()->translatedFormat('F Y'),
            'bedrag' => $this->usd($this->spentUsd),
            'eur' => $this->eur($this->spentUsd),
            'procent' => (int) round(($this->spentUsd / max($this->budgetUsd, 0.000001)) * 100),
            'budget' => $this->usd($this->budgetUsd),
        ])) . '</p>';

        $html .= '<p>' . e($this->threshold >= 100
            ? __('AI-aanroepen gaan gewoon door; het budget blokkeert niets. Pas het budget aan bij Instellingen, AI, of kijk in het AI-logboek welke functies de kosten maken.')
            : __('Dit is een waarschuwing vooraf. Bij 100% volgt nog één mail; daarna blijft het stil tot de volgende maand.')) . '</p>';

        $html .= '<p>' . e(__('De bedragen zijn schattingen op basis van de prijstabel in het CMS, niet de factuur van de provider.')) . '</p>';

        if ($url = $this->logUrl()) {
            $html .= '<p><a href="' . e($url) . '">' . e(__('Open het AI-logboek')) . '</a></p>';
        }

        return $html;
    }
}
