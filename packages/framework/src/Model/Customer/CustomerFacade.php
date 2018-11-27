<?php

namespace Shopsys\FrameworkBundle\Model\Customer;

use Doctrine\ORM\EntityManagerInterface;
use Shopsys\FrameworkBundle\Model\Customer\Mail\CustomerMailFacade;
use Shopsys\FrameworkBundle\Model\Order\Order;

class CustomerFacade
{
    /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    protected $em;

    /**
     * @var \Shopsys\FrameworkBundle\Model\Customer\UserRepository
     */
    protected $userRepository;

    /**
     * @var \Shopsys\FrameworkBundle\Model\Customer\CustomerDataFactoryInterface
     */
    protected $customerDataFactory;

    /**
     * @var \Shopsys\FrameworkBundle\Model\Customer\CustomerPasswordService
     */
    protected $customerPasswordService;

    /**
     * @var \Shopsys\FrameworkBundle\Model\Customer\Mail\CustomerMailFacade
     */
    protected $customerMailFacade;

    /**
     * @var \Shopsys\FrameworkBundle\Model\Customer\BillingAddressFactoryInterface
     */
    protected $billingAddressFactory;

    /**
     * @var \Shopsys\FrameworkBundle\Model\Customer\DeliveryAddressFactoryInterface
     */
    protected $deliveryAddressFactory;

    /**
     * @var \Shopsys\FrameworkBundle\Model\Customer\BillingAddressDataFactoryInterface
     */
    protected $billingAddressDataFactory;

    /**
     * @var \Shopsys\FrameworkBundle\Model\Customer\UserFactoryInterface
     */
    protected $userFactory;

    /**
     * @param \Doctrine\ORM\EntityManagerInterface $em
     * @param \Shopsys\FrameworkBundle\Model\Customer\UserRepository $userRepository
     * @param \Shopsys\FrameworkBundle\Model\Customer\CustomerDataFactoryInterface $customerDataFactory
     * @param \Shopsys\FrameworkBundle\Model\Customer\CustomerPasswordService $customerPasswordServiceService
     * @param \Shopsys\FrameworkBundle\Model\Customer\Mail\CustomerMailFacade $customerMailFacade
     * @param \Shopsys\FrameworkBundle\Model\Customer\BillingAddressFactoryInterface $billingAddressFactory
     * @param \Shopsys\FrameworkBundle\Model\Customer\DeliveryAddressFactoryInterface $deliveryAddressFactory
     * @param \Shopsys\FrameworkBundle\Model\Customer\BillingAddressDataFactoryInterface $billingAddressDataFactory
     * @param \Shopsys\FrameworkBundle\Model\Customer\UserFactoryInterface $userFactory
     */
    public function __construct(
        EntityManagerInterface $em,
        UserRepository $userRepository,
        CustomerDataFactoryInterface $customerDataFactory,
        CustomerPasswordService $customerPasswordServiceService,
        CustomerMailFacade $customerMailFacade,
        BillingAddressFactoryInterface $billingAddressFactory,
        DeliveryAddressFactoryInterface $deliveryAddressFactory,
        BillingAddressDataFactoryInterface $billingAddressDataFactory,
        UserFactoryInterface $userFactory
    ) {
        $this->em = $em;
        $this->userRepository = $userRepository;
        $this->customerDataFactory = $customerDataFactory;
        $this->customerPasswordService = $customerPasswordServiceService;
        $this->customerMailFacade = $customerMailFacade;
        $this->billingAddressFactory = $billingAddressFactory;
        $this->deliveryAddressFactory = $deliveryAddressFactory;
        $this->billingAddressDataFactory = $billingAddressDataFactory;
        $this->userFactory = $userFactory;
    }

    /**
     * @param int $userId
     * @return \Shopsys\FrameworkBundle\Model\Customer\User
     */
    public function getUserById($userId)
    {
        return $this->userRepository->getUserById($userId);
    }

    /**
     * @param string $email
     * @param int $domainId
     * @return \Shopsys\FrameworkBundle\Model\Customer\User|null
     */
    public function findUserByEmailAndDomain($email, $domainId)
    {
        return $this->userRepository->findUserByEmailAndDomain($email, $domainId);
    }

    /**
     * @param \Shopsys\FrameworkBundle\Model\Customer\UserData $userData
     * @return \Shopsys\FrameworkBundle\Model\Customer\User
     */
    public function register(UserData $userData)
    {
        $userByEmailAndDomain = $this->findUserByEmailAndDomain($userData->email, $userData->domainId);

        $billingAddressData = $this->billingAddressDataFactory->create();
        $billingAddress = $this->billingAddressFactory->create($billingAddressData);

        $user = $this->userFactory->create(
            $userData,
            $billingAddress,
            null,
            $userByEmailAndDomain
        );

        $this->em->persist($billingAddress);
        $this->em->persist($user);
        $this->em->flush();

        $this->customerMailFacade->sendRegistrationMail($user);

        return $user;
    }

    /**
     * @param \Shopsys\FrameworkBundle\Model\Customer\CustomerData $customerData
     * @return \Shopsys\FrameworkBundle\Model\Customer\User
     */
    public function create(CustomerData $customerData)
    {
        $toFlush = [];
        $billingAddress = $this->billingAddressFactory->create($customerData->billingAddressData);
        $this->em->persist($billingAddress);
        $toFlush[] = $billingAddress;

        $deliveryAddress = $this->deliveryAddressFactory->create($customerData->deliveryAddressData);
        if ($deliveryAddress !== null) {
            $this->em->persist($deliveryAddress);
            $toFlush[] = $deliveryAddress;
        }

        $userByEmailAndDomain = $this->findUserByEmailAndDomain(
            $customerData->userData->email,
            $customerData->userData->domainId
        );

        $user = $this->userFactory->create(
            $customerData->userData,
            $billingAddress,
            $deliveryAddress,
            $userByEmailAndDomain
        );
        $this->em->persist($user);
        $toFlush[] = $user;

        $this->em->flush($toFlush);

        if ($customerData->sendRegistrationMail) {
            $this->customerMailFacade->sendRegistrationMail($user);
        }

        return $user;
    }

    /**
     * @param int $userId
     * @param \Shopsys\FrameworkBundle\Model\Customer\CustomerData $customerData
     * @return \Shopsys\FrameworkBundle\Model\Customer\User
     */
    protected function edit($userId, CustomerData $customerData)
    {
        $user = $this->getUserById($userId);

        $user->edit($customerData->userData, $this->customerPasswordService);

        $user->getBillingAddress()->edit($customerData->billingAddressData);

        $user->editDeliveryAddress(
            $customerData->deliveryAddressData,
            $this->deliveryAddressFactory
        );

        return $user;
    }

    /**
     * @param int $userId
     * @param \Shopsys\FrameworkBundle\Model\Customer\CustomerData $customerData
     * @return \Shopsys\FrameworkBundle\Model\Customer\User
     */
    public function editByAdmin($userId, CustomerData $customerData)
    {
        $user = $this->edit($userId, $customerData);

        $userByEmailAndDomain = $this->findUserByEmailAndDomain(
            $customerData->userData->email,
            $customerData->userData->domainId
        );
        $user->changeEmail($customerData->userData->email, $userByEmailAndDomain);

        $this->em->flush();

        return $user;
    }

    /**
     * @param int $userId
     * @param \Shopsys\FrameworkBundle\Model\Customer\CustomerData $customerData
     * @return \Shopsys\FrameworkBundle\Model\Customer\User
     */
    public function editByCustomer($userId, CustomerData $customerData)
    {
        $user = $this->edit($userId, $customerData);

        $this->em->flush();

        return $user;
    }

    /**
     * @param int $userId
     */
    public function delete($userId)
    {
        $user = $this->getUserById($userId);

        $this->em->remove($user);
        $this->em->flush();
    }

    /**
     * @param \Shopsys\FrameworkBundle\Model\Customer\User $user
     * @param \Shopsys\FrameworkBundle\Model\Order\Order $order
     */
    public function amendCustomerDataFromOrder(User $user, Order $order)
    {
        $this->edit(
            $user->getId(),
            $this->customerDataFactory->createAmendedByOrder($user, $order)
        );

        $this->em->flush();
    }
}
