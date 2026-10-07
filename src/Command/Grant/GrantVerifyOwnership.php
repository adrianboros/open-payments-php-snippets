<?php

namespace App\Command\Grant;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

//@! start chunk 1 | title=Import dependencies
use OpenPayments\AuthClient;
use OpenPayments\Config\Config;
//@! end chunk 1

/**
 * Class GrantVerifyOwnership
 * @package App\Command\Grant
 *
 * This command requests a grant for subject information, to verify that the user owns a wallet address.
 * The user must approve the grant at the interaction URL.
 */
class GrantVerifyOwnership extends Command
{
    protected static $defaultName = 'grant:verify-ownership';

    protected function configure(): void
    {
        $this
            ->setDescription('Requests a grant to verify that the user owns a wallet address.')
            ->setHelp('This command outputs the interaction URL and the values needed to continue the grant.')
            ->addArgument(
                'USER_WALLET_ADDRESS',
                InputArgument::OPTIONAL,
                'The wallet address the user says they own.',
                $_ENV['WALLET_ADDRESS'] ?? null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $WALLET_ADDRESS =  $_ENV['WALLET_ADDRESS'];
        $PRIVATE_KEY = $_ENV['PRIVATE_KEY'];
        $KEY_ID = $_ENV['KEY_ID'];
        $USER_WALLET_ADDRESS = $input->getArgument('USER_WALLET_ADDRESS');

        //@! start chunk 2 | title=Initialize Open Payments client
        $config = new Config(
            $WALLET_ADDRESS,
            $PRIVATE_KEY,
            $KEY_ID
        );
        $opClient = new AuthClient($config);
        //@! end chunk 2

        //@! start chunk 3 | title=Get user wallet address information
        $userWalletAddress = $opClient->walletAddress()->get([
            'url' => $USER_WALLET_ADDRESS
        ]);
        //@! end chunk 3

        //@! start chunk 4 | title=Request grant for subject information
        $grant = $opClient->grant()->request(
            [
                'url' => $userWalletAddress->authServer
            ],
            [
                'subject' => [
                    'sub_ids' => [
                        [
                            'id' => $userWalletAddress->id,
                            'format' => 'uri',
                        ]
                    ]
                ],
                'client' => $config->getWalletAddressUrl(),
                'interact' => [
                    'start' => ['redirect'],
                    'finish' => [
                        'method' => 'redirect',
                        'uri' => 'https://localhost/?verify=123423',
                        'nonce' => bin2hex(random_bytes(16)),
                    ],
                ]
            ]
        );
        //@! end chunk 4

        //@! start chunk 5 | title=Check grant state
        if (!$grant instanceof \OpenPayments\Models\PendingGrant) {
            throw new \Error('Expected interactive grant');
        }
        //@! end chunk 5

        //@! start chunk 6 | title=Output
        $output->writeln('Please interact at the following URL: ' . $grant->interact->redirect);
        $output->writeln('CONTINUE_ACCESS_TOKEN = ' . $grant->continue->access_token->value);
        $output->writeln('CONTINUE_URI = ' . $grant->continue->uri);
        //@! end chunk 6

        return Command::SUCCESS;
    }
}
