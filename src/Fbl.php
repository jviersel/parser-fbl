<?php

namespace AbuseIO\Parsers;

use PhpMimeMailParser\Parser as MimeParser;
use AbuseIO\Models\Incident;

/**
 * Class Fbl
 * @package AbuseIO\Parsers
 */
class Fbl extends Parser
{
    /**
     * Create a new Fbl instance
     *
     * @param \PhpMimeMailParser\Parser $parsedMail phpMimeParser object
     * @param array $arfMail array with ARF detected results
     */
    public function __construct($parsedMail, $arfMail)
    {
        parent::__construct($parsedMail, $arfMail, $this);
    }

    /**
     * Parse attachments
     * @return array    Returns array with failed or success data
     *                  (See parser-common/src/Parser.php) for more info.
     */
    public function parse()
    {
        if ($this->arfMail !== true) {
            $this->feedName = 'default';

            // As this is a generic FBL parser we need to see which was the source and add the name
            // to the report, so its origin is clearly shown.
            $source = $this->parsedMail->getHeader('from');
            foreach (config("{$this->configBase}.parser.aliases") as $from => $alias) {
                if (preg_match($from, $source)) {
                    // If there is an alias, prefer that name instead of the from address
                    $source = $alias;

                    // If there is an more specific feed configuration prefer that config over the default
                    if (!empty(config("{$this->configBase}.feeds.{$source}"))) {
                        $this->feedName = $source;
                    }
                }
            }

            // If feed is known and enabled, validate data and save report
            if ($this->isKnownFeed() && $this->isEnabledFeed()) {
                // To get some more consitency, remove "\r" from the report.
                $this->arfMail['report'] = str_replace("\r", "", $this->arfMail['report']);

                // Build up the report
                preg_match_all(
                    "/([\w\-]+): (.*)[ ]*\n/m",
                    $this->arfMail['report'],
                    $matches
                );

                $report = array_combine($matches[1], $matches[2]);

                if (empty($report['Received-Date'])) {
                    if (!empty($report['Arrival-Date'])) {
                        $report['Received-Date'] = $report['Arrival-Date'];
                        unset($report['Arrival-Date']);
                    }
                }

                // Now parse the headers from the spam messages and add it to the report
                if (!empty($this->arfMail['evidence'])) {
                    $spamMessage = new MimeParser();
                    $spamMessage->setText($this->arfMail['evidence']);
                    $report['headers'] = $spamMessage->getHeaders();
                } else {
                    $this->failed(
                        'The e-mail received at the parser is not RFC822 compliant, and therefor not a FBL message'
                    );
                }

                // Also add the spam message body to the report
                $report['body'] = $spamMessage->getMessageBody();

                // Sanity check
                if ($this->hasRequiredFields($report) === true) {

                    // incident has all requirements met, filter and add!
                    $report = $this->applyFilters($report);

                    $incident = new Incident();
                    $incident->source      = $source; // FeedName
                    $incident->source_id   = false;
                    $incident->ip          = $report['Source-IP'];
                    $incident->domain      = false;
                    $incident->class       = config("{$this->configBase}.feeds.{$this->feedName}.class");
                    $incident->type        = config("{$this->configBase}.feeds.{$this->feedName}.type");
                    $incident->timestamp   = strtotime($report['Received-Date']);
                    $incident->information = json_encode($report);

                    $this->incidents[] = $incident;

                }
            }
        }

        return $this->success();
    }
}
