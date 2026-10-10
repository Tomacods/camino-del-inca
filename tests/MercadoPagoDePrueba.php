<?php

namespace Tests;

use Exception;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;

// Ocupa el lugar de la conexión del SDK con Mercado Pago en las pruebas: ninguna prueba llama a Mercado Pago de verdad.
// Los clientes del SDK (PreferenceClient, PaymentClient) son clases final y no se pueden reemplazar, pero todos mandan
// sus pedidos por el cliente HTTP de MercadoPagoConfig, que sí se puede cambiar. Éste contesta, en orden, lo que la
// prueba le indicó, y guarda los pedidos que recibió.
class MercadoPagoDePrueba implements MPHttpClient
{
    /** @var MPRequest[] */
    public array $pedidos = [];

    // Cada respuesta es un MPResponse, o null para «no responde».
    private array $respuestas = [];

    // Lo pone en lugar del cliente verdadero. Se llama en el setUp() de la prueba.
    public static function instalar(): self
    {
        $mercadoPago = new self;
        MercadoPagoConfig::setHttpClient($mercadoPago);

        return $mercadoPago;
    }

    // Deja otra vez el cliente verdadero, para que no quede el de mentira en la prueba siguiente. Va en el tearDown().
    public static function desinstalar(): void
    {
        MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
    }

    // Mercado Pago contesta con este contenido. Con un estado de error (400 o más), el SDK lanza MPApiException, como
    // hace el cliente verdadero.
    public function responder(array $contenido, int $estado = 200): self
    {
        $this->respuestas[] = new MPResponse($estado, $contenido);

        return $this;
    }

    // Mercado Pago no responde: el cliente verdadero lanza una Exception cuando no se puede conectar.
    public function noResponder(): self
    {
        $this->respuestas[] = null;

        return $this;
    }

    public function send(MPRequest $request): MPResponse
    {
        $this->pedidos[] = $request;

        if ($this->respuestas === []) {
            throw new Exception('La prueba no le indicó a Mercado Pago qué contestar.');
        }

        $respuesta = array_shift($this->respuestas);

        if ($respuesta === null) {
            throw new Exception('No se pudo conectar con Mercado Pago.');
        }

        if ($respuesta->getStatusCode() >= 400) {
            throw new MPApiException('Api error. Check response for details', $respuesta);
        }

        return $respuesta;
    }

    // Lo que se le mandó a Mercado Pago en un pedido, como arreglo.
    public function datosDelPedido(int $numero = 0): array
    {
        return json_decode($this->pedidos[$numero]->getPayload(), true);
    }
}
