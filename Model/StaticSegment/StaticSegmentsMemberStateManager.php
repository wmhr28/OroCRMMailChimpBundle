<?php

namespace OroCRM\Bundle\MailChimpBundle\Model\StaticSegment;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use OroCRM\Bundle\MailChimpBundle\Entity\StaticSegment;
use OroCRM\Bundle\MailChimpBundle\Entity\StaticSegmentMember;

class StaticSegmentsMemberStateManager
{
    /**
     * @var DoctrineHelper
     */
    protected $doctrineHelper;

    /**
     * @var string
     */
    protected $staticSegmentMember;

    /**
     * @var string
     */
    protected $mailChimpMember;

    /**
     * @var string
     */
    protected $extMergeVar;

    /**
     * @param DoctrineHelper $doctrineHelper
     * @param $staticSegmentMember
     * @param $mailChimpMember
     * @param $extMergeVar
     */
    public function __construct(DoctrineHelper $doctrineHelper, $staticSegmentMember, $mailChimpMember, $extMergeVar)
    {
        $this->doctrineHelper = $doctrineHelper;
        $this->staticSegmentMember = $staticSegmentMember;
        $this->mailChimpMember = $mailChimpMember;
        $this->extMergeVar = $extMergeVar;
    }

    /**
     * @param StaticSegment $staticSegment
     */
    public function handleMembers(StaticSegment $staticSegment)
    {
        $staticSegmentRep = $this->doctrineHelper->getEntityRepository($this->staticSegmentMember);

        $qb = $staticSegmentRep->createQueryBuilder('smmb');

        $deletedMembers = $qb
            ->select('IDENTITY(smmb.member) AS memberId')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('smmb.staticSegment', $staticSegment->getId()),
                    $qb->expr()->eq('smmb.state', ':stateUnsDel')
                )
            )
            ->setParameter('stateUnsDel', StaticSegmentMember::STATE_UNSUBSCRIBE_DELETE)
            ->getQuery()
            ->getArrayResult();

        $this->handleDroppedMembers($staticSegment);

        if (!empty($deletedMembers)) {
            $deletedMembersIds = array_map('current', $deletedMembers);
            $this->deleteMailChimpMembers($deletedMembersIds, $staticSegment->getSubscribersList());
            $this->deleteMailChimpMembersExtendedVars($deletedMembersIds, $staticSegment->getId());
        }
    }

    /**
     * @param StaticSegment $staticSegment
     */
    protected function handleDroppedMembers(StaticSegment $staticSegment)
    {
        $qb = $this->doctrineHelper
            ->getEntityManager($this->staticSegmentMember)
            ->createQueryBuilder('smmb');

        $qb
            ->delete($this->staticSegmentMember, 'smmb')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('smmb.staticSegment', $staticSegment->getId()),
                    $qb->expr()->in('smmb.state', ':states')
                )
            )
            ->setParameter('states', [StaticSegmentMember::STATE_DROP, StaticSegmentMember::STATE_UNSUBSCRIBE_DELETE])
            ->getQuery()
            ->execute();
    }

    /**
     * @param array $deletedMembersIds
     * @param $subscribersList
     */
    private function deleteMailChimpMembers(array $deletedMembersIds, $subscribersList)
    {
        $qb = $this->doctrineHelper
            ->getEntityManager($this->mailChimpMember)
            ->createQueryBuilder('mmb');

        $qb
            ->delete($this->mailChimpMember, 'mmb')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->in('mmb.id', ':deletedMembersIds'),
                    $qb->expr()->eq('mmb.subscribersList', $subscribersList->getId())
                )
            )
            ->setParameter('deletedMembersIds', $deletedMembersIds)
            ->getQuery()
            ->execute();
    }

    /**
     * @param array $deletedMembersIds
     * @param $staticSegmentId
     */
    private function deleteMailChimpMembersExtendedVars(array $deletedMembersIds, $staticSegmentId)
    {
        $qb = $this->doctrineHelper
            ->getEntityManager($this->extMergeVar)
            ->createQueryBuilder('evmmb');

        $qb
            ->delete($this->extMergeVar, 'evmmb')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->in('evmmb.member', ':deletedMembersIds'),
                    $qb->expr()->eq('evmmb.staticSegment', $staticSegmentId)
                )
            )
            ->setParameter('deletedMembersIds', $deletedMembersIds)
            ->getQuery()
            ->execute();
    }
}
