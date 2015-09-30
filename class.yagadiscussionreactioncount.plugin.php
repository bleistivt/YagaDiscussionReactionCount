<?php

$PluginInfo['YagaDiscussionReactionCount'] = [
    'Name' => 'Yaga Discussion Reaction Count',
    'Description' => 'Displays the total number of reactions of a discussion in the discussion meta. Run dba/counts after enabling if you already have reaction records.',
    'Version' => '0.2',
    'RequiredApplications' => ['Yaga' => '1.0'],
    'MobileFriendly' => true,
    'Author' => 'Bleistivt',
    'AuthorUrl' => 'http://bleistivt.net',
    'License' => 'GNU GPL2'
];

class YagaDiscussionReactionCountPlugin extends Gdn_Plugin {

    // If there are reactions, add the count to the DiscussionMeta everywhere.
    public function base_beforeDiscussionMeta_handler($sender, $args) {
        $countReactions = $args['Discussion']->CountReactions;

        if (C('Yaga.Reactions.Enabled') && $countReactions) {
            $number = bigPlural($countReactions, T('%s reaction'), T('%s reactions'));
            echo wrap(
                sprintf(pluralTranslate($countReactions, T('%s reaction'), T('%s reactions')), $number),
                'span',
                ['class' => 'MItem MCount ReactionCount']
            );
        }
    }


    // Count when a reaction is saved.
    public function reactionModel_afterReactionSave_handler($sender, $args) {
        $discussionID = false;
        // Can a DiscussionID be found for this item?
        if ($args['ParentType'] == 'discussion') {
            $discussionID = $args['ParentID'];
        } elseif ($args['ParentType'] == 'comment') {
            $record = Gdn::sql()
                ->getWhere('Comment', ['CommentID' => $args['ParentID']])
                ->firstRow(DATASET_TYPE_ARRAY);
            $discussionID = val('DiscussionID', $record);
        }

        // Does this action change the reaction count for this item?
        if ($args['Exists'] === false) {
            $incDec = ' - 1';
        } elseif (!$args['CurrentReaction']) {
            $incDec = ' + 1';
        } else return;

        Gdn::sql()
            ->update('User')
            ->set('CountReactions', 'CountReactions'.$incDec, false)
            ->where('UserID', $args['ParentUserID'])
            ->put();

        if (!$discussionID) return;
        // Update the count in the discussion table.
        Gdn::sql()
            ->update('Discussion')
            ->set('CountReactions', 'CountReactions'.$incDec, false)
            ->where('DiscussionID', $discussionID)
            ->put();
    }


    // Register the dba/counts handlers.
    public function dbaController_countJobs_handler($sender) {
        $sender->Data['Jobs']['Recalculate Discussion.CountReactions'] = '/plugin/yagadrcounts.json';
        $sender->Data['Jobs']['Recalculate User.CountReactions'] = '/plugin/yagaurcounts.json';
    }


    // Recalculate reaction counts for all discussions (including comments).
    public function pluginController_yagaDRcounts_create($sender, $from = false, $to = false) {
        $sender->permission('Garden.Settings.Manage');

        list($min, $max) = (new DBAModel())->primaryKeyRange('Discussion');
        if (!$from) {
            $from = $min;
            $to = $min + DBAModel::$ChunkSize - 1;
        }
        $from = (int)$from;
        $to = (int)$to;

        $database = Gdn::database(); 
        $px = $database->DatabasePrefix; 

        $database->query("
            update {$px}Discussion p
            where (p.DiscussionID >= {$from} and d.DiscussionID <= {$to})
            set p.CountReactions = (
                select count(c.ReactionID) 
                from {$px}Reaction c 
                where (p.DiscussionID = c.ParentID and c.ParentType = 'discussion') 
            ) + ( 
                select count(c.ReactionID) 
                from {$px}Reaction c 
                left join {$px}Comment j on j.CommentID = c.ParentID
                where (p.DiscussionID = j.DiscussionID and c.ParentType = 'comment')
            )
        ");

        $sender->setData('Result', [
            'Complete' => $to >= $max,
            'Percent' => min(round($to * 100 / $max), 100).'%',
            'Args' => [
                'from' => $to + 1,
                'to' => $from + DBAModel::$ChunkSize
            ]
        ]);
        $sender->renderData();
    }


    // Recalculate user reaction counts.
    public function pluginController_yagaURcounts_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        Gdn::database()->query(DBAModel::getCountSql(
            'count',
            'User', 'Reaction',
            'CountReactions', 'ReactionID',
            'UserID', 'ParentAuthorID'
        ));
        $sender->setData('Result', ['Complete' => true]);
        $sender->renderData();
    }


    // Create new columns to save the counts.
    public function structure() {
        $structure = Gdn::structure();

        $structure->table('Discussion')
            ->column('CountReactions', 'int(11)', 0)
            ->set();
        $structure->table('User')
            ->column('CountReactions', 'int(11)', 0)
            ->set();
    }


    public function setup() {
        $this->structure();
    }

}
