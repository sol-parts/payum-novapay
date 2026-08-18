<?php

/*
 * This file is part of Sol.parts
 *
 * (c) SOLPARTS LLC (EDRPOU 46143031) <mail@sol.parts>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SolParts\PayumNovaPay;

use Http\Discovery\Psr17FactoryDiscovery;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use SolParts\PayumNovaPay\Action\Api\DoCaptureAction;
use SolParts\PayumNovaPay\Action\AuthorizeAction;
use SolParts\PayumNovaPay\Action\CancelAction;
use SolParts\PayumNovaPay\Action\CaptureAction;
use SolParts\PayumNovaPay\Action\ConvertPaymentAction;
use SolParts\PayumNovaPay\Action\NotifyAction;
use SolParts\PayumNovaPay\Action\RefundAction;
use SolParts\PayumNovaPay\Action\StatusAction;
use SolParts\PayumNovaPay\Action\SyncAction;

/**
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class NovaPayGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'novapay',
            'payum.factory_title' => 'NovaPay',

            'payum.action.capture' => new CaptureAction(),
            'payum.action.authorize' => new AuthorizeAction(),
            'payum.action.refund' => new RefundAction(),
            'payum.action.cancel' => new CancelAction(),
            'payum.action.notify' => new NotifyAction(),
            'payum.action.status' => new StatusAction(),
            'payum.action.sync' => new SyncAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
            'payum.action.api.do_capture' => new DoCaptureAction(),
        ]);

        if (!$config['payum.api']) {
            $config['payum.default_options'] = [
                'sandbox' => true,
            ];
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = ['merchant_id', 'merchant_private_key'];

            $config['payum.api'] = static function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);

                return new Api(
                    (array) $config,
                    $config['payum.http_client'],
                    // Хост може передати власні PSR-17 фабрики цими опціями; без них —
                    // discovery, щоб конфіг із коробки працював з будь-якою PSR-17
                    // реалізацією (у payum/core таких опцій немає).
                    $config['payum.http_message_factory'] ?? Psr17FactoryDiscovery::findRequestFactory(),
                    $config['payum.http_stream_factory'] ?? Psr17FactoryDiscovery::findStreamFactory(),
                );
            };
        }
    }
}
