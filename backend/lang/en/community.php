<?php

return [
    'kind' => ['report' => 'Report', 'suggestion' => 'Suggestion'],
    'reason' => ['wrong_meaning' => 'Wrong meaning', 'typo' => 'Typo or spelling', 'awkward' => 'Awkward wording', 'offensive' => 'Offensive', 'wrong_speaker' => 'Wrong speaker', 'other' => 'Other'],
    'status' => ['open' => 'Waiting for review', 'accepted' => 'Accepted', 'rejected' => 'Not accepted', 'duplicate' => 'Duplicate'],
    'contributor_status' => ['applied' => 'Applied', 'approved' => 'Approved', 'suspended' => 'Suspended'],
    'bad_kind' => 'Unknown feedback type.',
    'must_own' => 'You can only report lines from packages you own.',
    'contributors_only' => 'Suggestions are open to approved contributors.',
    'not_in_package' => 'This line is not part of that package.',
    'suggestion_required' => 'Write the wording you suggest (different from the current one).',
    'already_open' => 'You already have an open report for this line.',
    'already_resolved' => 'This item was already resolved.',
    'reviewers_only' => 'Only reviewers can resolve community feedback.',
    'not_own' => 'You cannot resolve your own feedback.',
    'revision_comment' => 'Community feedback #:id by :name',
    'applied' => 'Thanks! We will review your application.',
    'suggestion_sent' => 'Thanks — your suggestion is waiting for review.',
    'mail' => [
        'subject' => ['accepted' => 'Your LUGHA feedback was accepted', 'rejected' => 'About your LUGHA feedback', 'duplicate' => 'About your LUGHA feedback'],
        'line' => [
            'report' => ['accepted' => 'You reported a line in :package and our reviewers confirmed the problem.', 'rejected' => 'We looked at the line you reported in :package and decided to keep it.', 'duplicate' => 'The line you reported in :package was already reported by someone else and is being handled.'],
            'suggestion' => ['accepted' => 'Your suggested wording for :package was accepted and will ship in the next update.', 'rejected' => 'We reviewed your suggestion for :package and decided not to use it this time.', 'duplicate' => 'A similar suggestion for this line in :package was already received.'],
        ],
        'applied' => 'New wording:',
        'note' => 'Reviewer note:',
        'thanks' => 'Thank you for helping make LUGHA better.',
    ],
];
