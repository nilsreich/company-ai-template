<?php

return ['proxies' => array_values(array_filter(explode(',', env('TRUSTED_PROXIES', ''))))];
