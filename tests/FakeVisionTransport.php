<?php
final class FakeVisionTransport implements App\Services\VisionTransport {
    public int $calls=0;
    public function __construct(private ?string $fixture=null,public string $value=''){}
    public function text(string $path):string {
        $this->calls++;if($this->fixture&&is_file($this->fixture)){$state=json_decode(file_get_contents($this->fixture),true);if(($state['failure'] ?? false))throw new RuntimeException('Mock OCR quota failure.');return $state['text'][hash_file('sha256',$path)] ?? '';}
        return $this->value;
    }
}
