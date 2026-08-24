<?php
/* Prints before it throws, so the test can assert the half-rendered buffer is discarded. */
echo 'half';
throw new RuntimeException('template failed');
