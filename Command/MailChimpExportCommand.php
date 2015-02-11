<?php

namespace OroCRM\Bundle\MailChimpBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\ImportExportBundle\Job\JobExecutor;
use Oro\Bundle\IntegrationBundle\Command\AbstractSyncCronCommand;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\IntegrationBundle\Provider\ReverseSyncProcessor;
use Oro\Component\Log\OutputLogger;
use OroCRM\Bundle\MailChimpBundle\Entity\Repository\StaticSegmentRepository;
use OroCRM\Bundle\MailChimpBundle\Entity\StaticSegment;
use OroCRM\Bundle\MailChimpBundle\Model\StaticSegment\StaticSegmentsMemberStateManager;
use OroCRM\Bundle\MailChimpBundle\Provider\Connector\MemberConnector;
use OroCRM\Bundle\MailChimpBundle\Provider\Connector\StaticSegmentConnector;

class MailChimpExportCommand extends AbstractSyncCronCommand
{
    /**
     * {@inheritdoc}
     */
    public function getDefaultDefinition()
    {
        return '*/5 * * * *';
    }

    /**
     * @var JobExecutor
     */
    protected $jobExecutor;

    /**
     * @var StaticSegmentsMemberStateManager
     */
    protected $reverseSyncProcessor;

    /**
     * @var StaticSegmentsMemberStateManager
     */
    protected $staticSegmentStateManager;

    /**
     * @var DoctrineHelper
     */
    protected $doctrineHelper;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('oro:cron:mailchimp:export')
            ->setDescription('Export members and static segments to MailChimp')
            ->addOption(
                'segments',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'MailChimp static StaticSegments to sync'
            );
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = new OutputLogger($output);
        $this->getContainer()->get('oro_integration.logger.strategy')->setLogger($logger);

        if ($this->isJobRunning(null)) {
            $logger->warning('Job already running. Terminating....');

            return;
        }

        $segments = $input->getOption('segments');
        /** @var StaticSegment[] $iterator */
        $iterator = $this->getStaticSegmentRepository()->getStaticSegmentsToSync($segments);

        $exportJobs = [
            MemberConnector::TYPE => MemberConnector::JOB_EXPORT,
            StaticSegmentConnector::TYPE => StaticSegmentConnector::JOB_EXPORT
        ];

        /** @var Channel[] $channelToSync */
        $channelToSync = [];
        $staticSegments = [];

        foreach ($iterator as $staticSegment) {
            $this->setStaticSegmentStatus($staticSegment, StaticSegment::STATUS_IN_PROGRESS);
            $channel = $staticSegment->getChannel();
            $channelToSync[$channel->getId()] = $channel;
            $staticSegments[$staticSegment->getId()] = $staticSegment;
        }

        foreach ($channelToSync as $id => $channel) {
            $output->writeln(sprintf('<info>Channel #%s:</info>', $id));
            foreach ($exportJobs as $type => $jobName) {
                $output->writeln(sprintf('    %s', $jobName));
                $this->getReverseSyncProcessor()->process($channel, $type, []);
            }
        }

        foreach ($staticSegments as $staticSegment) {
            $this->getStaticSegmentStateManager()->handleDroppedMembers($staticSegment);
            $this->setStaticSegmentStatus($staticSegment, StaticSegment::STATUS_SYNCED, true);
        }
    }

    /**
     * @param StaticSegment $staticSegment
     * @param string $status
     * @param bool $lastSynced
     */
    protected function setStaticSegmentStatus(StaticSegment $staticSegment, $status, $lastSynced = false)
    {
        $em = $this->getDoctrineHelper()->getEntityManager($staticSegment);

        /** @var StaticSegment $staticSegment */
        $staticSegment = $this->getDoctrineHelper()->getEntity(
            $this->getDoctrineHelper()->getEntityClass($staticSegment),
            $staticSegment->getId()
        );

        $staticSegment->setSyncStatus($status);

        if ($lastSynced) {
            $staticSegment->setLastSynced(new \DateTime('now', new \DateTimeZone('UTC')));
        }

        $em->persist($staticSegment);
        $em->flush($staticSegment);
    }

    /**
     * @return StaticSegmentRepository
     */
    protected function getStaticSegmentRepository()
    {
        return $this->getContainer()->get('doctrine')->getRepository(
            $this->getContainer()->getParameter('orocrm_mailchimp.entity.static_segment.class')
        );
    }

    /**
     * @return ReverseSyncProcessor
     */
    protected function getReverseSyncProcessor()
    {
        if (!$this->reverseSyncProcessor) {
            $this->reverseSyncProcessor = $this->getContainer()->get('oro_integration.reverse_sync.processor');
        }

        return $this->reverseSyncProcessor;
    }

    /**
     * @return StaticSegmentsMemberStateManager
     */
    protected function getStaticSegmentStateManager()
    {
        if (!$this->staticSegmentStateManager) {
            $this->staticSegmentStateManager = $this->getContainer()->get(
                'orocrm_mailchimp.static_segment_manager.state_manager'
            );
        }

        return $this->staticSegmentStateManager;
    }

    /**
     * @return DoctrineHelper
     */
    protected function getDoctrineHelper()
    {
        if (!$this->doctrineHelper) {
            $this->doctrineHelper = $this->getContainer()->get('oro_entity.doctrine_helper');
        }

        return $this->doctrineHelper;
    }
}
