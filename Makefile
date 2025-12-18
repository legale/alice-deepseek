.PHONY: clean test

test:
	./vendor/bin/phpunit

clean:
	rm -rf .phpunit.cache
	rm -rf tests/_output
	find . -type f -name "*.log" -delete
	find . -type d -name ".phpunit.cache" -exec rm -rf {} + 2>/dev/null || true

