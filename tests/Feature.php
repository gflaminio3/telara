<?php

use Illuminate\Support\Facades\Storage;

it('can write a file to the Telegram disk', function () {
    // Arrange: Set up mock content for the file
    $filePath = 'test_file.txt';
    $fileContent = 'This is a test file content from Telara ^_^';

    // Act: Store the file using the 'telegram' disk
    $result = Storage::disk('telegram')->put($filePath, $fileContent);

    // Assert: Verify the result is true
    expect($result)->toBeTrue();
});
