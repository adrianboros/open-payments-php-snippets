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
 * Class GrantVerifyOwnershipContinuation
 * @package App\Command\Grant
 *
 * This command continues the grant for subject information after the user approved it.
 * It outputs the verified wallet address.
 */
class GrantVerifyOwnershipContinuation extends Command
{
    protected static $defaultName = 'grant:verify-ownership:continuation';

    protected function configure(): void
    {
        $this
            ->setDescription('Continues the verify ownership grant and outputs the verified wallet address.')
            ->setHelp('Run this command after the user approved the grant at the interaction URL.')
            ->addArgument(
                'CONTINUE_ACCESS_TOKEN',
                InputArgument::OPTIONAL,
                'The value of CONTINUE_ACCESS_TOKEN',
                $_ENV['CONTINUE_ACCESS_TOKEN'] ?? null
            )
            ->addArgument(
                'URL_WITH_INTERACT_REF',
                InputArgument::OPTIONAL,
                'The value of URL_WITH_INTERACT_REF',
                $_ENV['URL_WITH_INTERACT_REF'] ?? null
            )
            ->addArgument(
                'CONTINUE_URI',
                InputArgument::OPTIONAL,
                'The value of CONTINUE_URI',
                $_ENV['CONTINUE_URI'] ?? null
            )
            ->addArgument(
                'USER_WALLET_ADDRESS',
                InputArgument::OPTIONAL,
                'The wallet address the user says they own. Use the same value as in grant:verify-ownership.',
                $_ENV['WALLET_ADDRESS'] ?? null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $WALLET_ADDRESS =  $_ENV['WALLET_ADDRESS'];
        $PRIVATE_KEY = $_ENV['PRIVATE_KEY'];
        $KEY_ID = $_ENV['KEY_ID'];

        $CONTINUE_ACCESS_TOKEN = $input->getArgument('CONTINUE_ACCESS_TOKEN');
        $URL_WITH_INTERACT_REF = $input->getArgument('URL_WITH_INTERACT_REF');
        $CONTINUE_URI = $input->getArgument('CONTINUE_URI');
        $USER_WALLET_ADDRESS = $input->getArgument('USER_WALLET_ADDRESS');
        $parse = parse_url($URL_WITH_INTERACT_REF);
        $query = $parse['query'] ?? '';
        parse_str($query, $params);
        $interactRef = $params['interact_ref'] ?? null;

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

        // The grant must be continued at the auth server (same scheme and host) of the claimed wallet address.
        $origin = fn (string $url) => parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . ':' . parse_url($url, PHP_URL_PORT);
        if ($origin($CONTINUE_URI) !== $origin($userWalletAddress->authServer)) {
            throw new \Error('The continue URI does not belong to the auth server of the wallet address.');
        }
        //@! end chunk 3

        //@! start chunk 4 | title=Continue grant
        $grant = $opClient->grant()->continue(
            [
                'access_token' => $CONTINUE_ACCESS_TOKEN,
                'url' => $CONTINUE_URI
            ],
            [
                'interact_ref' => $interactRef,
            ]
        );
        //@! end chunk 4

        //@! start chunk 5 | title=Verify ownership
        // The auth server must return the same wallet address that the user claimed.
        $subjectId = $grant->subject?->sub_ids[0]?->id ?? null;
        if ($subjectId !== $userWalletAddress->id) {
            throw new \Error('The wallet address ownership was not verified.');
        }
        //@! end chunk 5

        //@! start chunk 6 | title=Output
        $output->writeln('VERIFIED_WALLET_ADDRESS: ' . $subjectId);
        //@! end chunk 6

        return Command::SUCCESS;
    }
}
