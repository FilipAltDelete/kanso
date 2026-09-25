<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Kanso\Core\Internal\Domain\Customer\Customer;
use Kanso\Core\Internal\Domain\Customer\CustomerEvent;
use Kanso\Core\Internal\Domain\Customer\CustomerStoreInterface;
use Kanso\Core\Internal\Domain\Customer\DuplicateCustomerEmail;
use Symfony\Component\Uid\Uuid;

final class CustomerRepository implements CustomerStoreInterface
{
    /** API field to mapped property. */
    private const array SORT_PROPERTIES = [
        'name' => 'c.name',
        'email' => 'c.emailCanonical',
        'createdAt' => 'c.createdAt',
        'updatedAt' => 'c.updatedAt',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(string $id): ?Customer
    {
        return Uuid::isValid($id) ? $this->em->find(Customer::class, Uuid::fromString($id)) : null;
    }

    public function findByEmail(string $email): ?Customer
    {
        return $this->em->getRepository(Customer::class)->findOneBy(['emailCanonical' => Customer::canonicalEmail($email)]);
    }

    public function search(string $query, array $sort, int $offset, int $limit): array
    {
        $builder = $this->matching($query)->select('c');

        foreach ($sort as $field => $direction) {
            $builder->addOrderBy(self::SORT_PROPERTIES[$field], 'desc' === $direction ? 'DESC' : 'ASC');
        }
        // A stable order across pages when the sort field has ties.
        $builder->addOrderBy('c.id', 'ASC');

        /** @var list<Customer> $customers */
        $customers = $builder->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return $customers;
    }

    public function count(string $query): int
    {
        return (int) $this->matching($query)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
    }

    public function save(Customer $customer, CustomerEvent $event): void
    {
        // One flush is one transaction: the customer and its event land together or not at all.
        $this->em->persist($customer);
        $this->em->persist($event);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new DuplicateCustomerEmail('A customer with this email already exists.', 0, $e);
        }
    }

    public function events(Customer $customer, int $offset, int $limit): array
    {
        /** @var list<CustomerEvent> $events */
        $events = $this->em->createQueryBuilder()
            ->select('e')
            ->from(CustomerEvent::class, 'e')
            ->where('e.customerId = :customer')
            ->setParameter('customer', $customer->id(), 'uuid')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $events;
    }

    public function countEvents(Customer $customer): int
    {
        return $this->em->getRepository(CustomerEvent::class)->count(['customerId' => $customer->id()]);
    }

    private function matching(string $query): QueryBuilder
    {
        $builder = $this->em->createQueryBuilder()->from(Customer::class, 'c');

        $query = trim($query);
        if ('' !== $query) {
            // The name column's collation is case- and accent-insensitive; the
            // canonical email is lower case already.
            $builder
                ->where('c.name LIKE :query OR c.emailCanonical LIKE :email')
                ->setParameter('query', '%'.addcslashes($query, '%_\\').'%')
                ->setParameter('email', '%'.addcslashes(mb_strtolower($query), '%_\\').'%');
        }

        return $builder;
    }
}
