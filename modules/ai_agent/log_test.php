<?php
file_put_contents(__DIR__ . '/jobSearchRaw.log', "jobSearchRaw: [" . print_r($jobSearchRaw ?? 'NOT_SET', true) . "]\n", FILE_APPEND);
