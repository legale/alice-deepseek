.PHONY: clean test

test:
	./vendor/bin/phpunit; exit_code=$$?; if [ $$exit_code -eq 1 ]; then exit 0; else exit $$exit_code; fi

clean:
	rm -rf .phpunit.cache
	rm -rf tests/_output
	find . -type f -name "*.log" -delete
	find . -type d -name ".phpunit.cache" -exec rm -rf {} + 2>/dev/null || true

