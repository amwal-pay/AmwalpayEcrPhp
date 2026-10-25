<?php

declare(strict_types=1);

namespace AmwalExample\Web;

use AmwalExample\Core\AmountEntry;
use AmwalExample\Core\EcrExampleSettings;
use AmwalExample\Core\EcrMode;
use AmwalExample\Core\ResultPresenter;
use AmwalExample\Core\SelectedTerminalConfig;
use AmwalExample\Core\StateCompleted;
use AmwalExample\Core\StateInquired;
use AmwalExample\Core\Terminal;
use AmwalExample\Core\TransactionFormState;
use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Link\LanLink;
use AmwalPay\Ecr\Link\WebServiceLink;
use AmwalPay\Ecr\Result\ReceiptFailed;
use AmwalPay\Ecr\Result\ReceiptReady;
use AmwalPay\Ecr\Result\ReceiptUnavailable;

/**
 * The wire contract with the page: the same JSON shapes, names and wording as
 * the web sample's Dtos.cs, so the page is the same page.
 */
final class Views
{
    /** @return array<string, mixed> */
    public static function terminal(Terminal $t): array
    {
        return [
            'serialNumber' => $t->serialNumber,
            'name' => $t->name,
            'ecrMode' => $t->ecrMode,
            'modeLabel' => $t->modeLabel(),
            'ipAddress' => $t->ipAddress,
            'port' => $t->port,
            'terminalId' => $t->terminalId,
            'merchantId' => $t->merchantId,
            'displayName' => $t->displayName(),
            'connectionSummary' => $t->connectionSummary(),
            'supported' => $t->mode()->isSupportedInExample(),
        ];
    }

    /** The config card. Nothing about a stored key beyond whether one is set. */
    public static function config(SelectedTerminalConfig $c): array
    {
        return [
            'serialNumber' => $c->terminal->serialNumber,
            'modeLabel' => $c->modeLabel(),
            'transportLabel' => $c->transportLabel(),
            'connectionSummary' => $c->connectionSummary(),
            'keyLabel' => $c->hashKeyLabel(),
            'keyConfigured' => $c->hashKeyConfigured(),
            'keyStatus' => $c->hashKeyConfigured() ? 'configured' : 'not configured — set in Settings',
            'issues' => $c->issues(),
            'isReady' => $c->isReady(),
            'isSupported' => $c->isSupported(),
            'usesWebService' => $c->usesWebService(),
        ];
    }

    /** The settings the browser may see: deliberately no key value, only whether one is stored. */
    public static function settings(EcrExampleSettings $s): array
    {
        return [
            'environment' => $s->environment()->value,
            'wifi' => ['label' => self::keyLabel(EcrMode::Wifi), 'configured' => $s->wifiSecureHashKey() !== ''],
            'webService' => ['label' => self::keyLabel(EcrMode::WebService), 'configured' => $s->webServiceSecureHashKey() !== ''],
            'notice' => $s->saveError ?? $s->loadWarning,
        ];
    }

    /** The label the SDK plan uses for the mode's key, so it is not typed out a second time. */
    public static function keyLabel(EcrMode $mode): string
    {
        $link = $mode === EcrMode::WebService ? new WebServiceLink('1', '1') : new LanLink('127.0.0.1');
        return EcrSessions::plan($link, new EcrConfig())->signingKeyLabel();
    }

    /** @return array<string, mixed> */
    public static function transaction(TransactionSnapshot $run): array
    {
        $state = $run->state;
        $model = ResultPresenter::for($state, $run->usesWebService);
        $dialog = null;
        if ($model !== null) {
            $inquired = $state instanceof StateInquired ? $state : null;
            $receipt = $inquired?->receipt;
            $dialog = $model + [
                'unknownOutcomeWarning' => $model['isUnknownOutcome'] ? ResultPresenter::UNKNOWN_OUTCOME_WARNING : null,
                'receipt' => [
                    'showButton' => $model['offersReceipt'] && $inquired !== null && $inquired->receipt === null && !$inquired->fetchingReceipt,
                    'fetching' => $inquired?->fetchingReceipt === true,
                    'url' => $receipt instanceof ReceiptReady ? $receipt->url : null,
                    'problem' => match (true) {
                        $receipt instanceof ReceiptUnavailable => $receipt->reason !== '' ? $receipt->reason : 'No receipt is available for this transaction',
                        $receipt instanceof ReceiptFailed => $receipt->failure->message,
                        default => null,
                    },
                ],
                'canCloseReceipt' => $state instanceof StateCompleted,
            ];
            // Same key order as the web sample's DialogView.
            $dialog = array_merge(array_flip(['headline', 'tone', 'amount', 'message', 'note', 'fields', 'hints', 'rawPayload', 'isUnknownOutcome', 'inquirableReference', 'unknownOutcomeWarning', 'offersReceipt', 'receiptNote', 'receipt', 'canCloseReceipt']), $dialog);
        }
        return [
            'id' => $run->id,
            'terminalSerial' => $run->terminalSerial,
            'operation' => $run->request->type->displayName(),
            'state' => $state->name(),
            'busy' => $state->isBusy(),
            'isResult' => $state->isResult(),
            'statusText' => $state->name() === 'checking' ? 'Checking the terminal…' : 'Waiting for the POS terminal…',
            'version' => $run->version,
            'dialog' => $dialog,
            'timeoutSeconds' => $run->timeoutSeconds,
            'remainingSeconds' => $run->remainingSeconds,
        ];
    }

    /** `GET /api/meta`: what the page needs to build its form, from the SDK's flags and the form state. */
    public static function meta(): array
    {
        $operations = [];
        foreach (EcrTransactionType::menuOptions() as $type) {
            $layouts = [];
            foreach ([false, true] as $other) {
                foreach ([false, true] as $byReference) {
                    // Asked of the same form state the server validates with, so
                    // "shown" and "required" cannot drift apart.
                    $form = new TransactionFormState(type: $type, useOtherTerminal: $other, lookUpByReference: $byReference);
                    $layouts[($other ? '1' : '0') . ($byReference ? '1' : '0')] = [
                        'amount' => $type->requiresAmount(),
                        'merchantReference' => $form->showMerchantReference(),
                        'referenceSwitch' => $form->showReferenceSwitch(),
                        'originalReference' => $form->looksUpByReference(),
                        'receiptNumber' => $form->showReceiptNumber(),
                        'originalDate' => $form->showOriginalDate(),
                        'otherTerminalSwitch' => $form->showOtherTerminalSwitch(),
                        'originalTerminal' => $form->showOriginalTerminal(),
                    ];
                }
            }
            $operations[] = [
                'value' => $type->value,
                'label' => $type->displayName(),
                'requiresAmount' => $type->requiresAmount(),
                'requiresOriginalStan' => $type->requiresOriginalStan(),
                'requiresOriginalDate' => $type->requiresOriginalDate(),
                'allowsOtherTerminal' => $type->allowsOtherTerminal(),
                'movesMoney' => $type->movesMoney(),
                'amountHelp' => match (true) {
                    $type->requiresAmount() => '',
                    $type === EcrTransactionType::Inquiry => 'An inquiry only reads; nothing is charged',
                    default => "Uses the original transaction's amount",
                },
                'startButtonText' => $type === EcrTransactionType::Inquiry ? 'Look up transaction' : 'Start transaction',
                'layouts' => $layouts,
            ];
        }
        return [
            'operations' => $operations,
            'modes' => array_map(static fn (EcrMode $m): array => [
                'value' => $m->value,
                'label' => $m->label(),
                'ipBased' => $m->isIpBased(),
                'supported' => $m->isSupportedInExample(),
                'hint' => $m->isSupportedInExample() ? '' : 'Not available in this sample — the PHP SDK has no ' . ($m === EcrMode::UsbCable ? 'USB' : 'Bluetooth') . ' channel.',
                'note' => match ($m) {
                    EcrMode::UsbCable => 'USB cable ECR cannot be exercised from the PHP sample: the PHP SDK opens Wi-Fi and Web Service only.',
                    EcrMode::Bluetooth => 'Bluetooth ECR cannot be exercised from this sample.',
                    default => '',
                },
            ], EcrMode::all()),
            'environments' => array_map(static fn (EcrEnvironment $e): string => $e->value, EcrEnvironment::cases()),
            'amount' => [
                'min' => AmountEntry::formatMills(AmountEntry::MIN_MILLS),
                'max' => AmountEntry::formatMills(AmountEntry::MAX_MILLS),
                'fractionDigits' => AmountEntry::FRACTION_DIGITS,
                'maxDigits' => AmountEntry::maxDigits(),
            ],
            'merchantReferenceMaxLength' => TransactionFormState::MERCHANT_REFERENCE_MAX_LENGTH,
            'receiptNumberMaxLength' => TransactionFormState::RECEIPT_NUMBER_MAX_LENGTH,
            'defaultPort' => EcrConfig::DEFAULT_PORT,
            'today' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ];
    }
}
