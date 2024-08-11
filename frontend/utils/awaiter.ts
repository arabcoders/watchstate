const sleep = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));

// for non arrays, length is undefined, so != 0
const isNotTruthy = (val: any) => val === undefined || val === false || val === null || val.length === 0;

/**
 * Waits for the test function to return a truthy value.
 *
 * @param test - The function to test
 * @param timeout_ms - The maximum time to wait in milliseconds.
 * @param frequency - The frequency to check the test function in milliseconds.
 *
 * @returns The result of the test function.
 */
export default async function awaiter(test: Function, timeout_ms: number = 20 * 1000, frequency: number = 200) {
    if (typeof (test) != "function") {
        throw new Error("test should be a function in awaiter(test, [timeout_ms], [frequency])")
    }

    const endTime: number = Date.now() + timeout_ms;

    let result = test();

    while (isNotTruthy(result)) {
        if (Date.now() > endTime) {
            return false;
        }
        await sleep(frequency);
        result = test();
    }

    return result;
}

