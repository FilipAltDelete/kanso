<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** An order placed for a customer record, and that customer's order history. */
final class CustomerOrdersTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;
    private string $sku;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->signInAs(Role::OPERATOR);
        $this->api('POST', '/api/locations', ['code' => 'WH-'.bin2hex(random_bytes(3)), 'name' => 'Main']);
        $this->sku = (string) $this->api('POST', '/api/products', ['sku' => 'TEE-'.bin2hex(random_bytes(3)), 'name' => 'Tee'])['sku'];
    }

    public function testACustomersOrdersAreListedByTheirRecord(): void
    {
        $anna = $this->customer('Anna');
        $bo = $this->customer('Bo');
        $first = $this->order($anna);
        $second = $this->order($anna);
        $this->order($bo);
        $this->order(null);

        $history = $this->api('GET', '/api/orders?customer='.$anna);

        self::assertSame(200, $this->responseStatus());
        self::assertSame(2, $history['totalItems']);
        self::assertEqualsCanonicalizing([$first, $second], array_column($history['member'], 'number'));
        self::assertSame([$anna, $anna], array_column(array_column($history['member'], 'customer'), 'id'));
    }

    public function testAnOrderCanOnlyNameACustomerThatExists(): void
    {
        $problem = $this->api('POST', '/api/orders', $this->body('0199aaaa-0000-7000-8000-000000000001'));

        self::assertSame(422, $this->responseStatus());
        self::assertSame(['customer.id' => 'unknown_customer'], array_column($problem['violations'], 'code', 'path'));
    }

    public function testTheCustomerFilterMustBeAnId(): void
    {
        $this->api('GET', '/api/orders?customer=anna');

        self::assertSame(422, $this->responseStatus());
    }

    private function customer(string $name): string
    {
        $customer = $this->api('POST', '/api/customers', ['name' => $name, 'email' => strtolower($name).'-'.bin2hex(random_bytes(3)).'@example.com']);
        self::assertSame(201, $this->responseStatus(), json_encode($customer, \JSON_THROW_ON_ERROR));

        return (string) $customer['id'];
    }

    private function order(?string $customerId): string
    {
        $order = $this->api('POST', '/api/orders', $this->body($customerId));
        self::assertSame(201, $this->responseStatus(), json_encode($order, \JSON_THROW_ON_ERROR));

        return (string) $order['number'];
    }

    /** @return array<string, mixed> */
    private function body(?string $customerId): array
    {
        return [
            'customer' => ['name' => 'Someone', ...(null === $customerId ? [] : ['id' => $customerId])],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            'lines' => [['sku' => $this->sku, 'quantity' => 1, 'unitPrice' => 100]],
        ];
    }
}
